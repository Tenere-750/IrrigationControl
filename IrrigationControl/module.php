<?php
declare(strict_types=1);

class IrrigationControl extends IPSModule<?php
declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // properties
        $this->RegisterPropertyInteger("MasterID", 0);
        $this->RegisterPropertyInteger("PumpID", 0);
        $this->RegisterPropertyInteger("GlobalTravelTime", 7);
        $this->RegisterPropertyInteger("MaxParallelZones", 2);
        $this->RegisterPropertyString("ZoneList", "[]");

        // timers
        $this->RegisterTimer("PumpOnTimer", 0, 'IRR_PumpOnTimer($_IPS["TARGET"]);');
        $this->RegisterTimer("SequenceTimer", 0, 'IRR_SequenceTick($_IPS["TARGET"]);');

        // runtime buffers
        $this->SetBuffer('ActiveZones', '0');
        $this->SetBuffer('PumpOnPending', '0');

        // FIX: Valid JSON instead of empty string
        $this->WriteAttributeString('SequenceState', json_encode([]));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterWebFrontVariables();

        // ensure timers stopped
        $this->SetTimerInterval('PumpOnTimer', 0);
        $this->SetTimerInterval('SequenceTimer', 0);

        // ensure attribute exists
        if ($this->ReadAttributeString('SequenceState') === '') {
            $this->WriteAttributeString('SequenceState', json_encode([]));
        }
    }

    private function RegisterWebFrontVariables(): void
    {
        // Master
        if (@IPS_GetObjectIDByIdent("Master", $this->InstanceID) === false)
            $this->RegisterVariableBoolean("Master", "Master", "~Switch");
        $this->EnableAction("Master");

        // Pump
        if (@IPS_GetObjectIDByIdent("Pump", $this->InstanceID) === false)
            $this->RegisterVariableBoolean("Pump", "Pumpe", "~Switch");
        $this->EnableAction("Pump");

        // Zones
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones)) return;

        foreach ($zones as $i => $zone) {
            $ident = "Zone" . $i;

            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
                $this->RegisterVariableBoolean(
                    $ident,
                    $zone["Name"] ?? ("Zone " . ($i + 1)),
                    "~Switch"
                );
            } else {
                $vid = $this->GetIDForIdent($ident);
                if ($vid !== false && isset($zone["Name"])) {
                    IPS_SetName($vid, $zone["Name"]);
                }
            }

            $this->EnableAction($ident);
        }
    }

    // ------------------------------------------------------
    // RequestAction
    // ------------------------------------------------------
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === "Master") {
            $this->Master((bool)$Value);

            $state = $this->ReadMasterState($this->ReadPropertyInteger("MasterID"));
            SetValue($this->GetIDForIdent("Master"), $state);
            return;
        }

        if ($Ident === "Pump") {
            $this->Pump((bool)$Value);
            SetValue($this->GetIDForIdent("Pump"), (bool)$Value);
            return;
        }

        if (str_starts_with($Ident, "Zone")) {
            $index = intval(substr($Ident, 4));
            $ok = $this->SwitchZone($index, (bool)$Value);

            if ($ok)
                SetValue($this->GetIDForIdent($Ident), (bool)$Value);
            else {
                $cur = GetValue($this->GetIDForIdent($Ident));
                SetValue($this->GetIDForIdent($Ident), $cur);
            }
            return;
        }
    }

    // ------------------------------------------------------
    // Master
    // ------------------------------------------------------
    public function Master(bool $state): void
    {
        $masterID = $this->ReadPropertyInteger("MasterID");

        if ($masterID > 0 && IPS_InstanceExists($masterID))
            KNX_WriteDPT1($masterID, $state);

        if ($state === true)
            $this->AllOff();
    }

    // ------------------------------------------------------
    // Pump
    // ------------------------------------------------------
    public function Pump(bool $state): void
    {
        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0 && IPS_InstanceExists($pumpID))
            KNX_WriteDPT1($pumpID, $state);
    }

    // ------------------------------------------------------
    // AllOff
    // ------------------------------------------------------
    public function AllOff(): void
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];

        foreach ($zones as $i => $z) {
            $ventil = intval($z['Ventil'] ?? 0);

            if ($ventil > 0 && IPS_InstanceExists($ventil))
                KNX_WriteDPT1($ventil, false);

            $vid = $this->GetIDForIdent("Zone".$i);
            if ($vid !== false)
                SetValue($vid, false);
        }

        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0 && IPS_InstanceExists($pumpID))
            KNX_WriteDPT1($pumpID, false);

        $pvid = $this->GetIDForIdent("Pump");
        if ($pvid !== false)
            SetValue($pvid, false);

        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);

        // FIX: Reset sequence properly
        $this->WriteAttributeString('SequenceState', json_encode([]));
        $this->SetTimerInterval("SequenceTimer", 0);
    }

    // ------------------------------------------------------
    // SwitchZone
    // ------------------------------------------------------
    public function SwitchZone(int $zoneIndex, bool $state): bool
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];

        if (!isset($zones[$zoneIndex])) return false;

        // Master block
        if ($this->ReadMasterState($this->ReadPropertyInteger("MasterID")))
            return false;

        $zone = $zones[$zoneIndex];
        $ventil = intval($zone["Ventil"] ?? 0);
        $pumpID = $this->ReadPropertyInteger("PumpID");

        if ($ventil <= 0 || !IPS_InstanceExists($ventil))
            return false;

        if ($state) {
            KNX_WriteDPT1($ventil, true);

            $active = intval($this->GetBuffer("ActiveZones")) + 1;
            $this->SetBuffer("ActiveZones", $active);

            if ($active == 1) {
                $travel = intval($zone["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnTimer", max(0, $travel) * 1000);
            }
            return true;
        }

        // Off
        KNX_WriteDPT1($ventil, false);

        $active = intval($this->GetBuffer("ActiveZones")) - 1;
        if ($active < 0) $active = 0;
        $this->SetBuffer("ActiveZones", $active);

        if ($active === 0) {
            KNX_WriteDPT1($pumpID, false);
            $this->SetBuffer("PumpOnPending", "0");
            $this->SetTimerInterval("PumpOnTimer", 0);
        }
        return true;
    }

    // ------------------------------------------------------
    public function PumpOnTimer(): void
    {
        if ($this->GetBuffer("PumpOnPending") === "1") {
            $pumpID = $this->ReadPropertyInteger("PumpID");
            KNX_WriteDPT1($pumpID, true);

            $pvid = $this->GetIDForIdent("Pump");
            if ($pvid !== false)
                SetValue($pvid, true);
        }

        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);
    }

    // ------------------------------------------------------
    // SequenceTick (empty placeholder)
    // ------------------------------------------------------
    public function SequenceTick(): void
    {
        // placeholder for next step (keine Fehler mehr)
    }

    // ------------------------------------------------------
    private function ReadMasterState(int $masterID): bool
    {
        if ($masterID <= 0 || !IPS_InstanceExists($masterID)) return false;

        foreach (IPS_GetChildrenIDs($masterID) as $cid)
            if (IPS_VariableExists($cid))
                return (bool)GetValue($cid);

        if (function_exists("KNX_RequestStatus")) {
            $res = @KNX_RequestStatus($masterID);
            if (is_bool($res)) return $res;
        }

        if (IPS_VariableExists($masterID))
            return (bool)GetValue($masterID);

        return false;
    }
}

{
    public function Create()
    {
        parent::Create();

        // properties
        $this->RegisterPropertyInteger("MasterID", 0);
        $this->RegisterPropertyInteger("PumpID", 0);
        $this->RegisterPropertyInteger("GlobalTravelTime", 7); // seconds
        $this->RegisterPropertyInteger("MaxParallelZones", 2);
        $this->RegisterPropertyString("ZoneList", "[]");

        // timers
        $this->RegisterTimer("PumpOnTimer", 0, 'IRR_PumpOnTimer($_IPS[\'TARGET\']);');
        $this->RegisterTimer("SequenceTimer", 0, 'IRR_SequenceTick($_IPS[\'TARGET\']);');

        // runtime buffers
        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");

        // sequence state attribute: JSON or empty
        $this->WriteAttributeString('SequenceState', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterWebFrontVariables();
        // ensure timers not running on apply
        $this->SetTimerInterval("PumpOnTimer", 0);
        $this->SetTimerInterval("SequenceTimer", 0);
    }

    private function RegisterWebFrontVariables(): void
    {
        // Master
        if (@IPS_GetObjectIDByIdent("Master", $this->InstanceID) === false) {
            $this->RegisterVariableBoolean("Master", "Master", "~Switch");
        }
        $this->EnableAction("Master");

        // Pump
        if (@IPS_GetObjectIDByIdent("Pump", $this->InstanceID) === false) {
            $this->RegisterVariableBoolean("Pump", "Pumpe", "~Switch");
        }
        $this->EnableAction("Pump");

        // zones
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones)) return;
        foreach ($zones as $i => $zone) {
            $ident = "Zone" . $i;
            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
                $name = ($zone['Name'] ?? "Zone " . ($i + 1));
                $this->RegisterVariableBoolean($ident, $name, "~Switch");
            } else {
                // update name if changed
                $vid = $this->GetIDForIdent($ident);
                if ($vid !== false && isset($zone['Name'])) {
                    IPS_SetName($vid, $zone['Name']);
                }
            }
            $this->EnableAction($ident);
        }
    }

    // -----------------------------
    // RequestAction
    // -----------------------------
    public function RequestAction($Ident, $Value)
    {
        // master
        if ($Ident === "Master") {
            $this->Master((bool)$Value);
            // reflect real KNX state if possible
            $masterID = $this->ReadPropertyInteger("MasterID");
            $state = $this->ReadMasterState($masterID);
            SetValue($this->GetIDForIdent("Master"), $state);
            return;
        }

        // pump
        if ($Ident === "Pump") {
            $this->Pump((bool)$Value);
            SetValue($this->GetIDForIdent("Pump"), (bool)$Value);
            return;
        }

        // zones: ident = "ZoneX"
        if (str_starts_with($Ident, "Zone")) {
            $index = intval(substr($Ident, 4));
            $ok = $this->SwitchZone($index, (bool)$Value);
            if ($ok) {
                SetValue($this->GetIDForIdent($Ident), (bool)$Value);
            } else {
                // restore displayed value to actual
                $cur = GetValue($this->GetIDForIdent($Ident));
                SetValue($this->GetIDForIdent($Ident), $cur);
            }
            return;
        }
    }

    // -----------------------------
    // Master: set and AllOff on true
    // -----------------------------
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

    // -----------------------------
    // Pump manual
    // -----------------------------
    public function Pump(bool $state): void
    {
        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
            KNX_WriteDPT1($pumpID, $state);
        }
    }

    // -----------------------------
    // AllOff
    // -----------------------------
    public function AllOff(): void
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        foreach ($zones as $i => $z) {
            $ventil = intval($z['Ventil'] ?? 0);
            if ($ventil > 0 && IPS_InstanceExists($ventil)) {
                KNX_WriteDPT1($ventil, false);
            }
            $vid = $this->GetIDForIdent("Zone" . $i);
            if ($vid !== false) SetValue($vid, false);
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

        // stop any running sequence
        $this->WriteAttributeString('SequenceState', '');
        $this->SetTimerInterval("SequenceTimer", 0);
    }

    // -----------------------------
    // Core: check parallel-allow rule
    // returns true if zoneA may run parallel with zoneB
    // Implementation: allowed if either A lists B or B lists A (can be changed to require both)
    // -----------------------------
    private function allowedParallel(int $a, int $b): bool
    {
        if ($a === $b) return true;
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        if (!isset($zones[$a]) || !isset($zones[$b])) return false;

        $pa = trim((string)($zones[$a]['ParallelWith'] ?? ''));
        $pb = trim((string)($zones[$b]['ParallelWith'] ?? ''));

        $alist = array_filter(array_map('trim', explode(',', $pa)), fn($x) => $x !== '');
        $blist = array_filter(array_map('trim', explode(',', $pb)), fn($x) => $x !== '');

        if (in_array((string)$b, $alist, true) || in_array((string)$a, $blist, true)) {
            return true;
        }
        return false;
    }

    // -----------------------------
    // SwitchZone (manual or called by sequence)
    // - respects Master
    // - enforces MaxParallelZones and allowedParallel rules
    // - opens valve immediately; if first active: schedule pump on after travel time
    // - closes valve immediately; if last active: stop pump
    // -----------------------------
    public function SwitchZone(int $zoneIndex, bool $state): bool
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        if (!isset($zones[$zoneIndex])) {
            IPS_LogMessage('IrrigationControl', "SwitchZone: invalid index $zoneIndex");
            return false;
        }

        // Master read
        $masterID = $this->ReadPropertyInteger("MasterID");
        if ($this->ReadMasterState($masterID) === true) {
            IPS_LogMessage('IrrigationControl', "SwitchZone: blocked by Master (zone $zoneIndex)");
            return false;
        }

        $zone = $zones[$zoneIndex];
        $ventil = intval($zone['Ventil'] ?? 0);
        $pumpID = $this->ReadPropertyInteger('PumpID');
        $maxParallel = max(1, (int)$this->ReadPropertyInteger('MaxParallelZones'));

        if ($ventil <= 0 || !IPS_InstanceExists($ventil)) {
            IPS_LogMessage('IrrigationControl', "SwitchZone: invalid ventil for zone $zoneIndex");
            return false;
        }
        if ($pumpID <= 0 || !IPS_InstanceExists($pumpID)) {
            IPS_LogMessage('IrrigationControl', "SwitchZone: invalid pump instance");
            return false;
        }

        $active = intval($this->GetBuffer("ActiveZones"));

        if ($state === true) {
            // check how many active and which active
            $zonesList = $zones;
            $activeIndices = [];
            foreach ($zonesList as $i => $z) {
                $ident = $this->GetIDForIdent("Zone" . $i);
                if ($ident !== false && GetValue($ident) === true) $activeIndices[] = $i;
            }

            // if opening would exceed MaxParallel -> reject
            if (count($activeIndices) >= $maxParallel) {
                IPS_LogMessage('IrrigationControl', "SwitchZone: cannot open zone $zoneIndex — maxParallel reached");
                return false;
            }

            // check pairwise allowed: for each currently active j, ensure allowedParallel(j, zoneIndex) is true
            foreach ($activeIndices as $j) {
                if (!$this->allowedParallel($j, $zoneIndex)) {
                    IPS_LogMessage('IrrigationControl', "SwitchZone: zone $zoneIndex not allowed parallel with active zone $j");
                    return false;
                }
            }

            // ok -> open valve immediately
            KNX_WriteDPT1($ventil, true);

            // mark zone status variable (webfront) will be set by caller/RequestAction; but ensure variable exists
            if ($this->GetIDForIdent("Zone".$zoneIndex) !== false) {
                // don't overwrite here; RequestAction will set visible state.
            }

            // inc active
            $active++;
            $this->SetBuffer("ActiveZones", strval($active));

            // if first active -> schedule pump on after travel time (zone-specific fallback to global)
            if ($active === 1) {
                $travel = intval($zone['Verfahrzeit'] ?? $this->ReadPropertyInteger('GlobalTravelTime'));
                if ($travel < 0) $travel = 0;
                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnTimer", $travel * 1000);
            }

            return true;
        } else {
            // close valve now
            KNX_WriteDPT1($ventil, false);

            // dec active
            $active--;
            if ($active < 0) $active = 0;
            $this->SetBuffer("ActiveZones", strval($active));

            // if no active zones left -> stop pump
            if ($active === 0) {
                KNX_WriteDPT1($pumpID, false);
                if ($this->GetIDForIdent("Pump") !== false) SetValue($this->GetIDForIdent("Pump"), false);
                $this->SetBuffer("PumpOnPending", "0");
                $this->SetTimerInterval("PumpOnTimer", 0);
            }
            return true;
        }
    }

    // -----------------------------
    // PumpOnTimer: called after travel delay -> switch pump on
    // -----------------------------
    public function PumpOnTimer(): void
    {
        if ($this->GetBuffer("PumpOnPending") === "1") {
            $pumpID = $this->ReadPropertyInteger("PumpID");
            if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
                KNX_WriteDPT1($pumpID, true);
                if ($this->GetIDForIdent("Pump") !== false) SetValue($this->GetIDForIdent("Pump"), true);
            }
        }
        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);
    }

    // -----------------------------
    // Sequence: RunSequence(seqNumber)
    // non-blocking: sets SequenceState attribute and starts SequenceTimer to step through
    // SequenceState structure JSON:
    // { "seq":1, "order":[0,1,2], "step":0, "phase":"open_next"|"wait_runtime"|"closing", "runtime_end":timestamp, "pending_close": index }
    // -----------------------------
    public function RunSequence(int $seqNumber): void
    {
        // don't start if master active
        $masterID = $this->ReadPropertyInteger("MasterID");
        if ($this->ReadMasterState($masterID) === true) {
            IPS_LogMessage('IrrigationControl', "RunSequence: blocked by Master");
            return;
        }

        // get sequence order & runtimes
        $keyOrder = "Sequence{$seqNumber}Order";
        $keyEnabled = "Sequence{$seqNumber}Enabled";
        $keyStart = "Sequence{$seqNumber}Start";
        $orderStr = $this->ReadPropertyString($keyOrder);
        $enabled = (bool)$this->ReadPropertyBoolean($keyEnabled);
        $startTime = $this->ReadPropertyString($keyStart);

        // if disabled, don't run
        if (!$enabled && trim($orderStr)==='') {
            IPS_LogMessage('IrrigationControl', "RunSequence: sequence $seqNumber not enabled or no order");
            return;
        }

        // parse order string (comma separated indices)
        $items = array_values(array_filter(array_map('trim', explode(',', $orderStr)), fn($x) => $x !== ''));
        $order = array_map('intval', $items);

        if (count($order) === 0) {
            IPS_LogMessage('IrrigationControl', "RunSequence: empty order for $seqNumber");
            return;
        }

        // Build runtimes for this sequence from ZoneList
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        $runtimes = [];
        foreach ($order as $zi) {
            $zone = $zones[$zi] ?? [];
            $rtmin = intval($zone['RuntimeSeq'.$seqNumber] ?? 0);
            if ($rtmin <= 0) $rtmin = 1;
            $runtimes[] = $rtmin * 60; // convert to seconds
        }

        // Initialize SequenceState
        $state = [
            'seq' => $seqNumber,
            'order' => $order,
            'step' => 0,
            'phase' => 'open_next',
            'runtimes' => $runtimes,
            'runtime_end' => 0,
            'pending_close' => null
        ];
        $this->WriteAttributeString('SequenceState', json_encode($state));
        // start tick timer every 1s
        $this->SetTimerInterval("SequenceTimer", 1000);
        IPS_LogMessage('IrrigationControl', "RunSequence: started seq $seqNumber");
    }

    // -----------------------------
    // SequenceTick: called every second to advance sequence
    // -----------------------------
    public function SequenceTick(): void
    {
        $raw = $this->ReadAttributeString('SequenceState');
        if (empty($raw)) {
            $this->SetTimerInterval("SequenceTimer", 0);
            return;
        }
        $state = json_decode($raw, true);
        if (!is_array($state)) {
            $this->SetTimerInterval("SequenceTimer", 0);
            return;
        }

        $order = $state['order'];
        $step = intval($state['step']);
        $phase = $state['phase'];
        $now = time();

        // if sequence finished
        if ($step >= count($order)) {
            IPS_LogMessage('IrrigationControl', "SequenceTick: finished seq {$state['seq']}");
            // reset
            $this->WriteAttributeString('SequenceState', '');
            $this->SetTimerInterval("SequenceTimer", 0);
            return;
        }

        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];

        if ($phase === 'open_next') {
            // Open the next zone (index = order[step])
            $zi = intval($order[$step]);
            // If zone not enabled skip
            if (!isset($zones[$zi]) || empty($zones[$zi]['Enabled'])) {
                $state['step']++;
                $this->WriteAttributeString('SequenceState', json_encode($state));
                return;
            }
            // Try to open using SwitchZone (will check parallels)
            $ok = $this->SwitchZone($zi, true);
            if (!$ok) {
                // cannot open -> abort sequence
                IPS_LogMessage('IrrigationControl', "SequenceTick: cannot open zone $zi, aborting sequence.");
                $this->WriteAttributeString('SequenceState', '');
                $this->SetTimerInterval("SequenceTimer", 0);
                return;
            }
            // schedule runtime end = now + runtime_seconds[step]
            $runtimeSeconds = intval($state['runtimes'][$step] ?? 60);
            $state['runtime_end'] = $now + $runtimeSeconds;
            // set next phase wait_runtime
            $state['phase'] = 'wait_runtime';
            $this->WriteAttributeString('SequenceState', json_encode($state));
            return;
        }

        if ($phase === 'wait_runtime') {
            // wait until runtime_end reached
            if ($now >= intval($state['runtime_end'])) {
                // prepare to close the zone (but must close only after next is open OR if no next)
                $state['phase'] = 'closing';
                $this->WriteAttributeString('SequenceState', json_encode($state));
            }
            return;
        }

        if ($phase === 'closing') {
            // close current step zone, but open next first (if exists)
            $curIndex = intval($order[$step]);
            $nextStep = $step + 1;
            if ($nextStep < count($order)) {
                $nextIndex = intval($order[$nextStep]);
                // attempt to open next
                $ok = $this->SwitchZone($nextIndex, true);
                if (!$ok) {
                    // cannot open next -> close current and abort sequence gracefully
                    $this->SwitchZone($curIndex, false);
                    IPS_LogMessage('IrrigationControl', "SequenceTick: could not open next zone $nextIndex; closing current and aborting.");
                    $this->WriteAttributeString('SequenceState', '');
                    $this->SetTimerInterval("SequenceTimer", 0);
                    return;
                }
                // wait travel time of NEXT to ensure open -> then close CURRENT
                $travel = intval($zones[$nextIndex]['Verfahrzeit'] ?? $this->ReadPropertyInteger('GlobalTravelTime'));
                $delay = max(0, $travel);
                // set a small state that we'll close current after delay
                $state['phase'] = 'delayed_close';
                $state['delayed_close_at'] = $now + $delay;
                $state['pending_close'] = $curIndex;
                $this->WriteAttributeString('SequenceState', json_encode($state));
                return;
            } else {
                // no next -> just close current and finish step
                $this->SwitchZone($curIndex, false);
                $state['step']++;
                $state['phase'] = 'open_next';
                $this->WriteAttributeString('SequenceState', json_encode($state));
                return;
            }
        }

        if ($phase === 'delayed_close') {
            $at = intval($state['delayed_close_at'] ?? 0);
            if ($now >= $at) {
                $toClose = intval($state['pending_close']);
                $this->SwitchZone($toClose, false);
                // advance to next step (we already opened the next)
                $state['step']++;
                $state['phase'] = 'open_next';
                $state['pending_close'] = null;
                unset($state['delayed_close_at']);
                $this->WriteAttributeString('SequenceState', json_encode($state));
                return;
            }
            return;
        }
    }

    // -----------------------------
    // GetZones
    // -----------------------------
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
                'Ventil' => intval($z['Ventil'] ?? 0)
            ];
        }
        return $res;
    }

    // -----------------------------
    // Helper: read master state best-effort
    // -----------------------------
    private function ReadMasterState(int $masterID): bool
    {
        if ($masterID <= 0 || !IPS_InstanceExists($masterID)) return false;

        // try to read status variable child (common pattern for KNX)
        $children = @IPS_GetChildrenIDs($masterID);
        if (is_array($children)) {
            foreach ($children as $cid) {
                if (@IPS_VariableExists($cid)) {
                    return (bool)GetValue($cid);
                }
            }
        }

        // fallback: KNX_RequestStatus if available (may be async)
        if (function_exists('KNX_RequestStatus')) {
            $res = @KNX_RequestStatus($masterID);
            if (is_bool($res)) return $res;
        }

        // last fallback: try to read as variable directly
        if (@IPS_VariableExists($masterID)) {
            return (bool)GetValue($masterID);
        }

        return false;
    }
}
