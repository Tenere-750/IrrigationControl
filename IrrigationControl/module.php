<?php
declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties
        $this->RegisterPropertyInteger("MasterID", 0);
        $this->RegisterPropertyInteger("PumpID", 0);
        $this->RegisterPropertyInteger("GlobalTravelTime", 7); // seconds
        $this->RegisterPropertyInteger("MaxParallelZones", 2);
        $this->RegisterPropertyString("ZoneList", "[]");

        $this->RegisterPropertyString("Sequence1Start", "06:00");
        $this->RegisterPropertyString("Sequence2Start", "20:00");
        $this->RegisterPropertyBoolean("Sequence1Enabled", false);
        $this->RegisterPropertyBoolean("Sequence2Enabled", false);
        $this->RegisterPropertyString("Sequence1Order", "");
        $this->RegisterPropertyString("Sequence2Order", "");

        // Timers: pump delay and sequence tick
        $this->RegisterTimer("PumpOnTimer", 0, 'IRR_PumpOnTimer($_IPS[\'TARGET\']);');
        $this->RegisterTimer("SequenceTick", 0, 'IRR_SequenceTick($_IPS[\'TARGET\']);');

        // Buffers
        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");

        // Attribute to hold sequence state
        $this->RegisterAttributeString("SequenceState", json_encode([]));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // register WebFront variables only if not existing
        $this->RegisterWebFrontVariables();

        // stop timers on apply (safe state)
        $this->SetTimerInterval("PumpOnTimer", 0);
        $this->SetTimerInterval("SequenceTick", 0);
        $this->SetBuffer("PumpOnPending", "0");
        if ($this->GetBuffer("ActiveZones") === "") $this->SetBuffer("ActiveZones", "0");

        // update sequence end labels (for display)
        $this->UpdateSequenceEndLabels();
    }

    // -----------------------
    // WebFront variables (no duplicates)
    // -----------------------
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

        // Zones
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        foreach ($zones as $i => $zone) {
            $ident = "Zone" . $i;
            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
                $name = $zone["Name"] ?? ("Zone " . ($i + 1));
                $this->RegisterVariableBoolean($ident, $name, "~Switch");
            } else {
                // update name if changed
                $vid = $this->GetIDForIdent($ident);
                if ($vid !== false && isset($zone["Name"])) {
                    IPS_SetName($vid, $zone["Name"]);
                }
            }
            $this->EnableAction($ident);
        }

        
        // Sequence start variables for WebFront
        if (@IPS_GetObjectIDByIdent("Sequence1StartWF", $this->InstanceID) === false) {
            $this->RegisterVariableString("Sequence1StartWF", "Seq1 Startzeit", "");
        }
        $this->EnableAction("Sequence1StartWF");
        if (@IPS_GetObjectIDByIdent("Sequence2StartWF", $this->InstanceID) === false) {
            $this->RegisterVariableString("Sequence2StartWF", "Seq2 Startzeit", "");
        }
        $this->EnableAction("Sequence2StartWF");
// Sequence end display variables (read-only)
        if (@IPS_GetObjectIDByIdent("Sequence1End", $this->InstanceID) === false) {
            $this->RegisterVariableString("Sequence1End", "Sequence1 End", "");
        }
        if (@IPS_GetObjectIDByIdent("Sequence2End", $this->InstanceID) === false) {
            $this->RegisterVariableString("Sequence2End", "Sequence2 End", "");
        }
    }

    // -----------------------
    // RequestAction (WebFront)
    // -----------------------
    public function RequestAction($Ident, $Value)
    {
        
        if ($Ident === "Sequence1StartWF") {
            SetValue($this->GetIDForIdent("Sequence1StartWF"), $Value);
            $this->UpdateSequenceEndLabels();
            return;
        }
        if ($Ident === "Sequence2StartWF") {
            SetValue($this->GetIDForIdent("Sequence2StartWF"), $Value);
            $this->UpdateSequenceEndLabels();
            return;
        }

        if ($Ident === "Master") {
            $this->Master((bool)$Value);
            // refresh shown state from KNX variable if available
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
            $idx = intval(substr($Ident, 4));
            $ok = $this->SwitchZone($idx, (bool)$Value);
            if ($ok) {
                SetValue($this->GetIDForIdent($Ident), (bool)$Value);
            } else {
                // restore UI value
                $cur = GetValue($this->GetIDForIdent($Ident));
                SetValue($this->GetIDForIdent($Ident), $cur);
            }
            return;
        }
    }

    // -----------------------
    // Master / Pump / AllOff
    // -----------------------
    public function Master(bool $state): void
    {
        $mid = $this->ReadPropertyInteger("MasterID");
        if ($mid > 0 && IPS_InstanceExists($mid)) {
            KNX_WriteDPT1($mid, $state);
        }
        if ($state === true) {
            $this->AllOff();
        }
    }

    public function Pump(bool $state): void
    {
        $pid = $this->ReadPropertyInteger("PumpID");
        if ($pid > 0 && IPS_InstanceExists($pid)) {
            KNX_WriteDPT1($pid, $state);
        }
    }

    public function AllOff(): void
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        foreach ($zones as $i => $z) {
            $vent = intval($z["Ventil"] ?? 0);
            if ($vent > 0 && IPS_InstanceExists($vent)) {
                KNX_WriteDPT1($vent, false);
            }
            $vid = $this->GetIDForIdent("Zone" . $i);
            if ($vid !== false) SetValue($vid, false);
        }
        $pump = $this->ReadPropertyInteger("PumpID");
        if ($pump > 0 && IPS_InstanceExists($pump)) {
            KNX_WriteDPT1($pump, false);
        }
        if ($this->GetIDForIdent("Pump") !== false) SetValue($this->GetIDForIdent("Pump"), false);

        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);

        // stop sequence if running
        $this->WriteAttributeString("SequenceState", json_encode([]));
        $this->SetTimerInterval("SequenceTick", 0);

        // update end labels
        $this->UpdateSequenceEndLabels();
    }

    // -----------------------
    // Allowed Parallel logic (Model B)
    // -----------------------
    private function allowedParallel(int $a, int $b): bool
    {
        if ($a === $b) return true;
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        if (!isset($zones[$a]) || !isset($zones[$b])) return false;

        $pa = trim((string)($zones[$a]["ParallelWith"] ?? ""));
        $pb = trim((string)($zones[$b]["ParallelWith"] ?? ""));

        $la = array_filter(array_map("trim", explode(",", $pa)), fn($x) => $x !== "");
        $lb = array_filter(array_map("trim", explode(",", $pb)), fn($x) => $x !== "");

        return in_array((string)$b, $la, true) || in_array((string)$a, $lb, true);
    }

    // -----------------------
    // SwitchZone (manual or sequence)
    // returns bool success
    // -----------------------
    public function SwitchZone(int $zoneIndex, bool $state): bool
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        if (!isset($zones[$zoneIndex])) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: invalid zone $zoneIndex");
            return false;
        }

        // master check
        $masterID = $this->ReadPropertyInteger("MasterID");
        if ($this->ReadMasterState($masterID) === true) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: blocked by Master");
            return false;
        }

        $zone = $zones[$zoneIndex];
        $vent = intval($zone["Ventil"] ?? 0);
        $pump = $this->ReadPropertyInteger("PumpID");
        $maxParallel = max(1, (int)$this->ReadPropertyInteger("MaxParallelZones"));

        if ($vent <= 0 || !IPS_InstanceExists($vent)) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: invalid ventil for zone $zoneIndex");
            return false;
        }
        if ($pump <= 0 || !IPS_InstanceExists($pump)) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: invalid pump instance");
            return false;
        }

        // count currently active zones (by UI variable state)
        $currentActive = [];
        foreach ($zones as $i => $z) {
            $vid = $this->GetIDForIdent("Zone" . $i);
            if ($vid !== false && GetValue($vid) === true) $currentActive[] = $i;
        }

        // if enabling: enforce parallel rules
        if ($state === true) {
            if (count($currentActive) >= $maxParallel) {
                IPS_LogMessage("IrrigationControl", "SwitchZone: max parallel reached");
                return false;
            }
            foreach ($currentActive as $other) {
                if (!$this->allowedParallel($other, $zoneIndex)) {
                    IPS_LogMessage("IrrigationControl", "SwitchZone: zone $zoneIndex not allowed parallel with $other");
                    return false;
                }
            }

            // open valve immediately
            KNX_WriteDPT1($vent, true);

            // increase active counter
            $active = intval($this->GetBuffer("ActiveZones")) + 1;
            $this->SetBuffer("ActiveZones", strval($active));

            // if first active -> schedule pump on after travel time
            if ($active === 1) {
                $travel = intval($zone["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnTimer", max(0, $travel) * 1000);
            }

            return true;
        }

        // disabling zone
        KNX_WriteDPT1($vent, false);

        $active = intval($this->GetBuffer("ActiveZones")) - 1;
        if ($active < 0) $active = 0;
        $this->SetBuffer("ActiveZones", strval($active));

        if ($active === 0) {
            // stop pump
            KNX_WriteDPT1($pump, false);
            if ($this->GetIDForIdent("Pump") !== false) SetValue($this->GetIDForIdent("Pump"), false);
            $this->SetBuffer("PumpOnPending", "0");
            $this->SetTimerInterval("PumpOnTimer", 0);
        }

        return true;
    }

    // -----------------------
    // PumpOnTimer (non-blocking)
    // -----------------------
    public function PumpOnTimer(): void
    {
        if ($this->GetBuffer("PumpOnPending") === "1") {
            $pump = $this->ReadPropertyInteger("PumpID");
            if ($pump > 0 && IPS_InstanceExists($pump)) {
                KNX_WriteDPT1($pump, true);
                if ($this->GetIDForIdent("Pump") !== false) SetValue($this->GetIDForIdent("Pump"), true);
            }
        }
        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);
    }

    // -----------------------
    // Sequence control (RunSequence + tick)
    // -----------------------
    public function RunSequence(int $seqNumber): void
    {
        // block if master active
        if ($this->ReadMasterState($this->ReadPropertyInteger("MasterID"))) {
            IPS_LogMessage("IrrigationControl", "RunSequence $seqNumber blocked by Master");
            return;
        }

        // read order string from property keys name: Sequence1Order / Sequence2Order
        $orderStr = $this->ReadPropertyString("Sequence" . $seqNumber . "Order");
        $enabled = (bool)$this->ReadPropertyBoolean("Sequence" . $seqNumber . "Enabled");

        if (!$enabled) {
            IPS_LogMessage("IrrigationControl", "RunSequence $seqNumber not enabled");
            return;
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $orderStr)), fn($x) => $x !== ''));
        $order = array_map('intval', $items);
        if (count($order) === 0) {
            IPS_LogMessage("IrrigationControl", "RunSequence $seqNumber empty order");
            return;
        }

        // filter order by schedule for today
        $filtered = [];
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        foreach ($order as $zi) {
            if (!isset($zones[$zi])) continue;
            $z = $zones[$zi];
            if (empty($z["Enabled"])) continue;
            if ($this->ZoneShouldRunToday($z)) {
                $filtered[] = $zi;
            }
        }
        if (count($filtered) === 0) {
            IPS_LogMessage("IrrigationControl", "RunSequence $seqNumber nothing to run today");
            return;
        }

        // build runtimes from zone RuntimeSeq1/2
        $runtimes = [];
        foreach ($filtered as $zi) {
            $rmin = intval($zones[$zi]["RuntimeSeq" . $seqNumber] ?? 0);
            if ($rmin <= 0) $rmin = 1;
            $runtimes[] = $rmin * 60; // seconds
        }

        $state = [
            "seq" => $seqNumber,
            "order" => $filtered,
            "step" => 0,
            "phase" => "open_next",
            "runtimes" => $runtimes,
            "runtime_end" => 0,
            "pending_close" => null
        ];

        $this->WriteAttributeString("SequenceState", json_encode($state));
        $this->SetTimerInterval("SequenceTick", 1000);
        IPS_LogMessage("IrrigationControl", "RunSequence $seqNumber started with " . count($filtered) . " zones");
    }

    public function SequenceTick(): void
    {
        $raw = $this->ReadAttributeString("SequenceState");
        if (empty($raw)) {
            $this->SetTimerInterval("SequenceTick", 0);
            return;
        }
        $state = json_decode($raw, true);
        if (!is_array($state) || empty($state["order"])) {
            $this->WriteAttributeString("SequenceState", json_encode([]));
            $this->SetTimerInterval("SequenceTick", 0);
            return;
        }

        $now = time();
        $order = $state["order"];
        $step = intval($state["step"]);
        $phase = $state["phase"];
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];

        // finished?
        if ($step >= count($order)) {
            $this->WriteAttributeString("SequenceState", json_encode([]));
            $this->SetTimerInterval("SequenceTick", 0);
            IPS_LogMessage("IrrigationControl", "Sequence finished");
            $this->UpdateSequenceEndLabels();
            return;
        }

        if ($phase === "open_next") {
            $zi = intval($order[$step]);
            $ok = $this->SwitchZone($zi, true);
            if (!$ok) {
                // abort
                IPS_LogMessage("IrrigationControl", "SequenceTick: cannot open zone $zi -> abort");
                $this->WriteAttributeString("SequenceState", json_encode([]));
                $this->SetTimerInterval("SequenceTick", 0);
                $this->UpdateSequenceEndLabels();
                return;
            }
            // set runtime_end
            $runtimeSeconds = intval($state["runtimes"][$step] ?? 60);
            $state["runtime_end"] = $now + $runtimeSeconds;
            $state["phase"] = "wait_runtime";
            $this->WriteAttributeString("SequenceState", json_encode($state));
            return;
        }

        if ($phase === "wait_runtime") {
            if ($now >= intval($state["runtime_end"])) {
                $state["phase"] = "closing";
                $this->WriteAttributeString("SequenceState", json_encode($state));
            }
            return;
        }

        if ($phase === "closing") {
            $cur = intval($order[$step]);
            $nextStep = $step + 1;
            if ($nextStep < count($order)) {
                $next = intval($order[$nextStep]);
                $ok = $this->SwitchZone($next, true);
                if (!$ok) {
                    // cannot open next -> close current and abort
                    $this->SwitchZone($cur, false);
                    IPS_LogMessage("IrrigationControl", "SequenceTick: cannot open next $next -> abort");
                    $this->WriteAttributeString("SequenceState", json_encode([]));
                    $this->SetTimerInterval("SequenceTick", 0);
                    $this->UpdateSequenceEndLabels();
                    return;
                }
                // wait next's travel time then close current
                $travel = intval($zones[$next]["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
                $state["phase"] = "delayed_close";
                $state["delayed_close_at"] = $now + max(0, $travel);
                $state["pending_close"] = $cur;
                $this->WriteAttributeString("SequenceState", json_encode($state));
                return;
            } else {
                // no next: close current and advance
                $this->SwitchZone($cur, false);
                $state["step"]++;
                $state["phase"] = "open_next";
                $this->WriteAttributeString("SequenceState", json_encode($state));
                return;
            }
        }

        if ($phase === "delayed_close") {
            $at = intval($state["delayed_close_at"] ?? 0);
            if ($now >= $at) {
                $toClose = intval($state["pending_close"]);
                $this->SwitchZone($toClose, false);
                $state["step"]++;
                $state["phase"] = "open_next";
                unset($state["delayed_close_at"]);
                $state["pending_close"] = null;
                $this->WriteAttributeString("SequenceState", json_encode($state));
            }
            return;
        }
    }

    // -----------------------
    // Helpers: schedule decision & end-time calc
    // -----------------------
    private function ZoneShouldRunToday(array $zone): bool
    {
        $type = strtolower(trim((string)($zone["ScheduleType"] ?? "daily")));
        if ($type === "" || $type === "daily") return true;
        $epochDays = intval(floor(time() / 86400));
        if ($type === "every_n") {
            $n = max(1, intval($zone["EveryN"] ?? 1));
            return ($epochDays % $n) === 0;
        }
        if ($type === "weekdays") {
            $wd = trim((string)($zone["Weekdays"] ?? ""));
            if ($wd === "") return true;
            $list = array_filter(array_map("trim", explode(",", $wd)), fn($x) => $x !== "");
            $today = intval(date("N")); // 1..7
            return in_array((string)$today, $list, true) || in_array($today, array_map('intval', $list), true);
        }
        return true;
    }

    // calculate end time string for a given sequence based on today's zones & runtimes & travel times
    private function CalculateSequenceEndTime(int $seqNumber): string
    {
        $start = $this->GetValue($this->GetIDForIdent("Sequence{$ = seqNumber}StartWF"));
        if (empty($start)) return "";
        $orderStr = $this->ReadPropertyString("Sequence" . $seqNumber . "Order");
        $items = array_values(array_filter(array_map('trim', explode(',', $orderStr)), fn($x) => $x !== ''));
        $order = array_map('intval', $items);

        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        $totalSeconds = 0;
        $prevIndex = null;

        foreach ($order as $zi) {
            if (!isset($zones[$zi])) continue;
            $z = $zones[$zi];
            if (empty($z["Enabled"])) continue;
            if (!$this->ZoneShouldRunToday($z)) continue;

            // add travel time of this zone (to open) if previous exists and must wait? We approximate by adding this zone's Verfahrzeit before pump start only for the first zone.
            if ($prevIndex === null) {
                $totalSeconds += intval($z["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
            } else {
                // when zones run sequentially, we must add the next zone's Verfahrzeit as delay between opening next and closing previous.
                $totalSeconds += intval($z["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
            }

            // add runtime
            $rtmin = intval($z["RuntimeSeq" . $seqNumber] ?? 0);
            if ($rtmin <= 0) $rtmin = 1;
            $totalSeconds += $rtmin * 60;

            $prevIndex = $zi;
        }

        if ($totalSeconds === 0) return "";

        // parse start HH:MM
        $parts = explode(":", $start);
        if (count($parts) < 2) return "";
        $h = intval($parts[0]);
        $m = intval($parts[1]);
        $startTs = mktime($h, $m, 0);
        $endTs = $startTs + $totalSeconds;
        return date("H:i", $endTs);
    }

    // update Sequence1End/Sequence2End UI variables
    private function UpdateSequenceEndLabels(): void
    {
        $e1 = $this->CalculateSequenceEndTime(1);
        $e2 = $this->CalculateSequenceEndTime(2);
        if ($this->GetIDForIdent("Sequence1End") !== false) SetValue($this->GetIDForIdent("Sequence1End"), $e1);
        if ($this->GetIDForIdent("Sequence2End") !== false) SetValue($this->GetIDForIdent("Sequence2End"), $e2);
    }

    // -----------------------
    // Sequence scheduling helper (call after starttime change)
    // We'll support manual RunSequence buttons; automatic scheduling can be added later.
    // -----------------------

    // -----------------------
    // GetZones (info)
    // -----------------------
    public function GetZones(): array
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];
        $res = [];
        foreach ($zones as $i => $z) {
            $res[] = [
                "Index" => $i,
                "Name" => ($z["Name"] ?? "Zone " . ($i + 1)),
                "Enabled" => (bool)($z["Enabled"] ?? false),
                "State" => ($this->GetIDForIdent("Zone" . $i) !== false) ? GetValue($this->GetIDForIdent("Zone" . $i)) : false,
                "Ventil" => intval($z["Ventil"] ?? 0)
            ];
        }
        return $res;
    }

    // -----------------------
    // ReadMasterState best-effort
    // -----------------------
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
