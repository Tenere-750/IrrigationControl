<?php
declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // core properties (müssen zu form.json passen)
        $this->RegisterPropertyInteger("MasterID", 0);
        $this->RegisterPropertyInteger("PumpID", 0);
        $this->RegisterPropertyInteger("GlobalTravelTime", 7);
        $this->RegisterPropertyInteger("MaxParallelZones", 2);
        $this->RegisterPropertyString("ZoneList", "[]");

        // sequence properties (existieren durch form.json, aber registrieren für defaults)
        $this->RegisterPropertyBoolean("Sequence1Enabled", false);
        $this->RegisterPropertyString("Sequence1Start", "06:00");
        $this->RegisterPropertyString("Sequence1Order", "");

        $this->RegisterPropertyBoolean("Sequence2Enabled", false);
        $this->RegisterPropertyString("Sequence2Start", "20:00");
        $this->RegisterPropertyString("Sequence2Order", "");

        // timers (RegisterTimer darf in Create aufgerufen werden)
        $this->RegisterTimer("PumpOnTimer", 0, "IRR_PumpOnTimer(\$_IPS['TARGET']);");
        $this->RegisterTimer("SequenceTimer", 0, "IRR_SequenceTick(\$_IPS['TARGET']);");

        // buffers
        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");

        // attribute: holds last-run timestamps per sequence and per zone
        $this->RegisterAttributeString("LastRun", json_encode(['1'=>[],'2'=>[]]));
        $this->RegisterAttributeString("SequenceState", "");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // ensure WebFront variables exist
        $this->RegisterWebFrontVariables();

        // stop timers on Apply
        $this->SetTimerInterval("PumpOnTimer", 0);
        $this->SetTimerInterval("SequenceTimer", 0);
        if ($this->GetBuffer("ActiveZones") === "") $this->SetBuffer("ActiveZones", "0");
    }

    private function RegisterWebFrontVariables(): void
    {
        // Master variable
        if (@IPS_GetObjectIDByIdent("Master", $this->InstanceID) === false) {
            $this->RegisterVariableBoolean("Master", "Master", "~Switch");
        }
        $this->EnableAction("Master");

        // Pump variable
        if (@IPS_GetObjectIDByIdent("Pump", $this->InstanceID) === false) {
            $this->RegisterVariableBoolean("Pump", "Pumpe", "~Switch");
        }
        $this->EnableAction("Pump");

        // Zone variables
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        foreach ($zones as $i => $zone) {
            $ident = "Zone" . $i;
            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
                $name = $zone['Name'] ?? ("Zone " . ($i + 1));
                $this->RegisterVariableBoolean($ident, $name, "~Switch");
            } else {
                // update label if name changed
                $vid = $this->GetIDForIdent($ident);
                if ($vid !== false && isset($zone['Name'])) {
                    IPS_SetName($vid, $zone['Name']);
                }
            }
            $this->EnableAction($ident);
        }
    }

    // RequestAction für WebFront
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === "Master") {
            $this->Master((bool)$Value);
            // read actual master state best-effort and show
            $state = $this->ReadMasterState($this->ReadPropertyInteger("MasterID"));
            $vid = $this->GetIDForIdent("Master");
            if ($vid !== false) SetValue($vid, $state);
            return;
        }

        if ($Ident === "Pump") {
            $this->Pump((bool)$Value);
            $vid = $this->GetIDForIdent("Pump");
            if ($vid !== false) SetValue($vid, (bool)$Value);
            return;
        }

        if (str_starts_with($Ident, "Zone")) {
            $index = intval(substr($Ident, 4));
            $ok = $this->SwitchZone($index, (bool)$Value);
            $vid = $this->GetIDForIdent($Ident);
            if ($vid !== false) {
                if ($ok) SetValue($vid, (bool)$Value);
                else {
                    // restore displayed value to actual
                    $cur = GetValue($vid);
                    SetValue($vid, $cur);
                }
            }
            return;
        }
    }

    // Master setzt Not-Aus
    public function Master(bool $state): void
    {
        $masterID = $this->ReadPropertyInteger("MasterID");
        if ($masterID > 0 && IPS_InstanceExists($masterID)) {
            KNX_WriteDPT1($masterID, $state);
        }
        if ($state === true) {
            $this->AllOff();
        }
    }

    // Pumpe manuell
    public function Pump(bool $state): void
    {
        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
            KNX_WriteDPT1($pumpID, $state);
        }
    }

    // Alle Ventile zu, Pumpe aus, Sequence stoppen
    public function AllOff(): void
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        foreach ($zones as $i => $z) {
            $vid = intval($z['Ventil'] ?? 0);
            if ($vid > 0 && IPS_InstanceExists($vid)) {
                KNX_WriteDPT1($vid, false);
            }
            $varid = $this->GetIDForIdent("Zone".$i);
            if ($varid !== false) SetValue($varid, false);
        }

        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
            KNX_WriteDPT1($pumpID, false);
        }
        $pvid = $this->GetIDForIdent("Pump");
        if ($pvid !== false) SetValue($pvid, false);

        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);

        // stop sequence
        $this->WriteAttributeString("SequenceState", "");
        $this->SetTimerInterval("SequenceTimer", 0);
    }

    // Prüft, ob Zone für Sequenz seqNumber heute dran ist (RunEvery / LastRun)
    private function zoneDueToday(int $zoneIndex, int $seqNumber): bool
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        if (!isset($zones[$zoneIndex])) return false;

        $runEvery = max(1, intval($zones[$zoneIndex]['RunEvery'] ?? 1));
        // get last run map
        $lastRunRaw = $this->ReadAttributeString("LastRun");
        $lastMap = json_decode($lastRunRaw, true) ?? ['1'=>[],'2'=>[]];
        $last = $lastMap[strval($seqNumber)][$zoneIndex] ?? 0;

        if ($last === 0) return true; // never run -> due

        // compute day difference using date (not exact seconds)
        $lastDate = (int)floor($last / 86400);
        $todayDate = (int)floor(time() / 86400);
        $daysSince = $todayDate - $lastDate;
        return ($daysSince >= $runEvery);
    }

    // Set last run stamp for a zone/sequence to today
    private function setLastRun(int $zoneIndex, int $seqNumber): void
    {
        $raw = $this->ReadAttributeString("LastRun");
        $map = json_decode($raw, true) ?? ['1'=>[],'2'=>[]];
        if (!isset($map[strval($seqNumber)])) $map[strval($seqNumber)] = [];
        $map[strval($seqNumber)][$zoneIndex] = time();
        $this->WriteAttributeString("LastRun", json_encode($map));
    }

    // erlaubtParallel-Regel wie beschrieben (A lists B or B lists A)
    private function allowedParallel(int $a, int $b): bool
    {
        if ($a === $b) return true;
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        if (!isset($zones[$a]) || !isset($zones[$b])) return false;
        $pa = trim((string)($zones[$a]['ParallelWith'] ?? ''));
        $pb = trim((string)($zones[$b]['ParallelWith'] ?? ''));
        $alist = array_filter(array_map('trim', explode(',', $pa)), fn($x) => $x !== '');
        $blist = array_filter(array_map('trim', explode(',', $pb)), fn($x) => $x !== '');
        return in_array((string)$b, $alist, true) || in_array((string)$a, $blist, true);
    }

    // Zonen schalten (manuell oder Sequenz)
    public function SwitchZone(int $zoneIndex, bool $state): bool
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        if (!isset($zones[$zoneIndex])) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: invalid zone $zoneIndex");
            return false;
        }

        // Master block
        $masterID = $this->ReadPropertyInteger("MasterID");
        if ($this->ReadMasterState($masterID) === true) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: blocked by Master (zone $zoneIndex)");
            return false;
        }

        $ventil = intval($zones[$zoneIndex]['Ventil'] ?? 0);
        $pumpID = $this->ReadPropertyInteger("PumpID");
        $maxParallel = max(1, intval($this->ReadPropertyInteger("MaxParallelZones")));

        if ($ventil <= 0 || !IPS_InstanceExists($ventil)) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: invalid ventil for zone $zoneIndex");
            return false;
        }
        if ($pumpID <= 0 || !IPS_InstanceExists($pumpID)) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: invalid pump instance");
            return false;
        }

        // collect currently active zones (from WebFront variables)
        $activeIndices = [];
        foreach ($zones as $i => $_) {
            $vid = $this->GetIDForIdent("Zone".$i);
            if ($vid !== false && GetValue($vid) === true) $activeIndices[] = $i;
        }

        if ($state === true) {
            // check max parallel
            if (count($activeIndices) >= $maxParallel) {
                IPS_LogMessage("IrrigationControl", "SwitchZone: cannot open zone $zoneIndex — MaxParallel reached");
                return false;
            }
            // check pairwise allowed
            foreach ($activeIndices as $j) {
                if (!$this->allowedParallel($j, $zoneIndex)) {
                    IPS_LogMessage("IrrigationControl", "SwitchZone: zone $zoneIndex not allowed parallel with active zone $j");
                    return false;
                }
            }

            // open valve
            KNX_WriteDPT1($ventil, true);

            // increment active buffer
            $active = intval($this->GetBuffer("ActiveZones")) + 1;
            $this->SetBuffer("ActiveZones", strval($active));

            // if first active -> schedule pump on after travel time (zone-specific fallback to global)
            if ($active === 1) {
                $travel = max(0, intval($zones[$zoneIndex]['Verfahrzeit'] ?? $this->ReadPropertyInteger("GlobalTravelTime")));
                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnTimer", $travel * 1000);
            }

            return true;
        } else {
            // close valve
            KNX_WriteDPT1($ventil, false);

            // decrement active
            $active = intval($this->GetBuffer("ActiveZones")) - 1;
            if ($active < 0) $active = 0;
            $this->SetBuffer("ActiveZones", strval($active));

            // if no active left -> stop pump
            if ($active === 0) {
                KNX_WriteDPT1($pumpID, false);
                $pvid = $this->GetIDForIdent("Pump");
                if ($pvid !== false) SetValue($pvid, false);
                $this->SetBuffer("PumpOnPending", "0");
                $this->SetTimerInterval("PumpOnTimer", 0);
            }

            return true;
        }
    }

    // PumpOnTimer: nach Verfahrzeit Pumpe EIN
    public function PumpOnTimer(): void
    {
        if ($this->GetBuffer("PumpOnPending") === "1") {
            $pumpID = $this->ReadPropertyInteger("PumpID");
            if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
                KNX_WriteDPT1($pumpID, true);
                $pvid = $this->GetIDForIdent("Pump");
                if ($pvid !== false) SetValue($pvid, true);
            }
        }
        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);
    }

    // RunSequence jetzt starten (public)
    public function RunSequence(int $seqNumber): void
    {
        // blocked by master?
        if ($this->ReadMasterState($this->ReadPropertyInteger("MasterID"))) {
            IPS_LogMessage("IrrigationControl", "RunSequence: blocked by Master");
            return;
        }

        // get order & enabled
        $enabled = (bool)$this->ReadPropertyBoolean("Sequence{$seqNumber}Enabled");
        $orderStr = $this->ReadPropertyString("Sequence{$seqNumber}Order");
        if (!$enabled && trim($orderStr) === "") {
            IPS_LogMessage("IrrigationControl", "RunSequence: sequence $seqNumber not enabled or no order");
            return;
        }

        // parse order and keep only zones due today and enabled
        $items = array_values(array_filter(array_map('trim', explode(',', $orderStr)), fn($x) => $x !== ''));
        $order = array_map('intval', $items);

        // filter by zone Enabled and dueToday
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        $filtered = [];
        foreach ($order as $zi) {
            if (!isset($zones[$zi])) continue;
            if (empty($zones[$zi]['Enabled'])) continue;
            if (!$this->zoneDueToday($zi, $seqNumber)) continue;
            $filtered[] = $zi;
        }

        if (count($filtered) === 0) {
            IPS_LogMessage("IrrigationControl", "RunSequence: no zones due for seq $seqNumber today");
            return;
        }

        // build runtimes for filtered order
        $runtimes = [];
        foreach ($filtered as $zi) {
            $rtmin = intval($zones[$zi]['RuntimeSeq'.$seqNumber] ?? 0);
            if ($rtmin <= 0) $rtmin = intval($zones[$zi]['RuntimeMorning'] ?? $zones[$zi]['RuntimeEvening'] ?? 5);
            $runtimes[] = $rtmin * 60;
        }

        // init sequence state
        $state = [
            'seq' => $seqNumber,
            'order' => $filtered,
            'step' => 0,
            'phase' => 'open_next',
            'runtimes' => $runtimes,
            'runtime_end' => 0,
            'pending_close' => null,
            'delayed_close_at' => 0
        ];
        $this->WriteAttributeString("SequenceState", json_encode($state));
        $this->SetTimerInterval("SequenceTimer", 1000);
        IPS_LogMessage("IrrigationControl", "RunSequence: started seq $seqNumber with " . count($filtered) . " zones");
    }

    // SequenceTick: state machine ticks once per second
    public function SequenceTick(): void
    {
        $raw = $this->ReadAttributeString("SequenceState");
        if (empty($raw)) {
            $this->SetTimerInterval("SequenceTimer", 0);
            return;
        }
        $state = json_decode($raw, true);
        if (!is_array($state)) {
            $this->WriteAttributeString("SequenceState", "");
            $this->SetTimerInterval("SequenceTimer", 0);
            return;
        }

        $order = $state['order'];
        $step = intval($state['step']);
        $phase = $state['phase'];
        $now = time();
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];

        // finished
        if ($step >= count($order)) {
            IPS_LogMessage("IrrigationControl", "SequenceTick: finished seq {$state['seq']}");
            $this->WriteAttributeString("SequenceState", "");
            $this->SetTimerInterval("SequenceTimer", 0);
            return;
        }

        // phases
        if ($phase === 'open_next') {
            $zi = intval($order[$step]);
            // open this zone (respect checks inside SwitchZone)
            $ok = $this->SwitchZone($zi, true);
            if (!$ok) {
                IPS_LogMessage("IrrigationControl", "SequenceTick: cannot open zone $zi, aborting sequence.");
                $this->WriteAttributeString("SequenceState", "");
                $this->SetTimerInterval("SequenceTimer", 0);
                return;
            }
            // schedule runtime end
            $runtimeSeconds = intval($state['runtimes'][$step] ?? 60);
            $state['runtime_end'] = $now + $runtimeSeconds;
            $state['phase'] = 'wait_runtime';
            $this->WriteAttributeString("SequenceState", json_encode($state));
            return;
        }

        if ($phase === 'wait_runtime') {
            if ($now >= intval($state['runtime_end'])) {
                $state['phase'] = 'closing';
                $this->WriteAttributeString("SequenceState", json_encode($state));
            }
            return;
        }

        if ($phase === 'closing') {
            $curIndex = intval($order[$step]);
            $nextStep = $step + 1;
            if ($nextStep < count($order)) {
                $nextIndex = intval($order[$nextStep]);
                // attempt to open next
                $ok = $this->SwitchZone($nextIndex, true);
                if (!$ok) {
                    // cannot open next -> close current, abort
                    $this->SwitchZone($curIndex, false);
                    IPS_LogMessage("IrrigationControl", "SequenceTick: could not open next zone $nextIndex; closing current and aborting.");
                    $this->WriteAttributeString("SequenceState", "");
                    $this->SetTimerInterval("SequenceTimer", 0);
                    return;
                }
                // wait travel time of NEXT before closing current
                $travel = intval($zones[$nextIndex]['Verfahrzeit'] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
                $state['phase'] = 'delayed_close';
                $state['delayed_close_at'] = $now + max(0, $travel);
                $state['pending_close'] = $curIndex;
                $this->WriteAttributeString("SequenceState", json_encode($state));
                return;
            } else {
                // no next -> close current, mark last run, advance
                $this->SwitchZone($curIndex, false);
                $this->setLastRun($curIndex, $state['seq']);
                $state['step']++;
                $state['phase'] = 'open_next';
                $this->WriteAttributeString("SequenceState", json_encode($state));
                return;
            }
        }

        if ($phase === 'delayed_close') {
            $at = intval($state['delayed_close_at'] ?? 0);
            if ($now >= $at) {
                $toClose = intval($state['pending_close']);
                $this->SwitchZone($toClose, false);
                $this->setLastRun($toClose, $state['seq']);
                // advance to next step
                $state['step']++;
                $state['phase'] = 'open_next';
                $state['pending_close'] = null;
                $state['delayed_close_at'] = 0;
                $this->WriteAttributeString("SequenceState", json_encode($state));
                return;
            }
            return;
        }
    }

    // Ausgabe der Zonen (Index, Name, Enabled, State, Ventil)
    public function GetZones(): array
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        $res = [];
        foreach ($zones as $i => $z) {
            $res[] = [
                'Index' => $i,
                'Name' => ($z['Name'] ?? "Zone " . ($i+1)),
                'Enabled' => (bool)($z['Enabled'] ?? false),
                'State' => ($this->GetIDForIdent("Zone".$i) !== false) ? GetValue($this->GetIDForIdent("Zone".$i)) : false,
                'Ventil' => intval($z['Ventil'] ?? 0),
                'RunEvery' => intval($z['RunEvery'] ?? 1)
            ];
        }
        return $res;
    }

    // Read master state best-effort
    private function ReadMasterState(int $masterID): bool
    {
        if ($masterID <= 0 || !IPS_InstanceExists($masterID)) return false;
        $children = @IPS_GetChildrenIDs($masterID);
        if (is_array($children)) {
            foreach ($children as $cid) {
                if (@IPS_VariableExists($cid)) {
                    return (bool)GetValue($cid);
                }
            }
        }
        if (function_exists("KNX_RequestStatus")) {
            $res = @KNX_RequestStatus($masterID);
            if (is_bool($res)) return $res;
        }
        if (@IPS_VariableExists($masterID)) {
            return (bool)GetValue($masterID);
        }
        return false;
    }
}
