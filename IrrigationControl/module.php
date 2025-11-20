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
        $this->RegisterPropertyInteger("GlobalTravelTime", 7);
        $this->RegisterPropertyInteger("MaxParallelZones", 2);
        $this->RegisterPropertyString("ZoneList", "[]");

        // Timers
        $this->RegisterTimer("PumpOnTimer", 0, 'IRR_PumpOnTimer($_IPS["TARGET"]);');
        $this->RegisterTimer("SequenceTimer", 0, 'IRR_SequenceTick($_IPS["TARGET"]);');

        // Buffers
        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");

        // Attributes
        $this->RegisterAttributeString("SequenceState", json_encode([]));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterWebFrontVariables();

        // Ensure timers stopped
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

        // Zones
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];

        foreach ($zones as $i => $zone) {
            $ident = "Zone" . $i;

            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
                $name = $zone["Name"] ?? ("Zone " . ($i + 1));
                $this->RegisterVariableBoolean($ident, $name, "~Switch");
            } else {
                $vid = $this->GetIDForIdent($ident);
                if ($vid !== false && isset($zone["Name"])) {
                    IPS_SetName($vid, $zone["Name"]);
                }
            }

            $this->EnableAction($ident);
        }
    }

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
            $i = intval(substr($Ident, 4));
            $ok = $this->SwitchZone($i, (bool)$Value);

            if ($ok) {
                SetValue($this->GetIDForIdent($Ident), (bool)$Value);
            } else {
                $cur = GetValue($this->GetIDForIdent($Ident));
                SetValue($this->GetIDForIdent($Ident), $cur);
            }
            return;
        }
    }

    public function Master(bool $state): void
    {
        $id = $this->ReadPropertyInteger("MasterID");

        if ($id > 0 && IPS_InstanceExists($id)) {
            KNX_WriteDPT1($id, $state);
        }

        if ($state) {
            $this->AllOff();
        }
    }

    public function Pump(bool $state): void
    {
        $id = $this->ReadPropertyInteger("PumpID");

        if ($id > 0 && IPS_InstanceExists($id)) {
            KNX_WriteDPT1($id, $state);
        }
    }

    public function AllOff(): void
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];

        foreach ($zones as $i => $z) {
            $ventil = intval($z["Ventil"] ?? 0);
            if ($ventil > 0 && IPS_InstanceExists($ventil)) {
                KNX_WriteDPT1($ventil, false);
            }

            $vid = $this->GetIDForIdent("Zone" . $i);
            if ($vid !== false) {
                SetValue($vid, false);
            }
        }

        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
            KNX_WriteDPT1($pumpID, false);
        }

        $pvid = $this->GetIDForIdent("Pump");
        if ($pvid !== false) {
            SetValue($pvid, false);
        }

        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");

        $this->SetTimerInterval("PumpOnTimer", 0);
        $this->SetTimerInterval("SequenceTimer", 0);
        $this->WriteAttributeString("SequenceState", json_encode([]));
    }

    private function allowedParallel(int $a, int $b): bool
    {
        if ($a === $b) return true;

        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];

        $pa = trim((string)($zones[$a]["ParallelWith"] ?? ""));
        $pb = trim((string)($zones[$b]["ParallelWith"] ?? ""));

        $la = array_filter(array_map("trim", explode(",", $pa)));
        $lb = array_filter(array_map("trim", explode(",", $pb)));

        return in_array((string)$b, $la, true) || in_array((string)$a, $lb, true);
    }

    public function SwitchZone(int $i, bool $state): bool
    {
        // FIXED quote error here ↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓↓
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true) ?? [];

        if (!isset($zones[$i])) return false;

        if ($this->ReadMasterState($this->ReadPropertyInteger("MasterID"))) {
            return false;
        }

        $ventil = intval($zones[$i]["Ventil"] ?? 0);
        $pumpID  = $this->ReadPropertyInteger("PumpID");

        if ($ventil <= 0 || !IPS_InstanceExists($ventil)) {
            return false;
        }

        $active = intval($this->GetBuffer("ActiveZones"));

        if ($state) {

            $max = (int)$this->ReadPropertyInteger("MaxParallelZones");

            $currentActive = [];
            foreach ($zones as $zid => $zinfo) {
                $vid = $this->GetIDForIdent("Zone" . $zid);
                if ($vid !== false && GetValue($vid)) {
                    $currentActive[] = $zid;
                }
            }

            if (count($currentActive) >= $max) {
                return false;
            }

            foreach ($currentActive as $other) {
                if (!$this->allowedParallel($i, $other)) {
                    return false;
                }
            }

            KNX_WriteDPT1($ventil, true);

            $active++;
            $this->SetBuffer("ActiveZones", (string)$active);

            if ($active === 1) {
                $travel = intval($zones[$i]["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnTimer", max(0, $travel) * 1000);
            }

            return true;
        }

        // Deactivate
        KNX_WriteDPT1($ventil, false);

        $active--;
        if ($active < 0) $active = 0;
        $this->SetBuffer("ActiveZones", (string)$active);

        if ($active === 0) {
            KNX_WriteDPT1($pumpID, false);
            $pvid = $this->GetIDForIdent("Pump");
            if ($pvid !== false) {
                SetValue($pvid, false);
            }
        }

        return true;
    }

    public function PumpOnTimer(): void
    {
        if ($this->GetBuffer("PumpOnPending") === "1") {
            $pumpID = $this->ReadPropertyInteger("PumpID");
            KNX_WriteDPT1($pumpID, true);

            $pvid = $this->GetIDForIdent("Pump");
            if ($pvid !== false) {
                SetValue($pvid, true);
            }
        }

        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);
    }

    public function SequenceTick(): void
    {
        // placeholder for future sequencer
    }

    private function ReadMasterState(int $id): bool
    {
        if ($id <= 0 || !IPS_InstanceExists($id)) return false;

        $children = @IPS_GetChildrenIDs($id);
        if (is_array($children)) {
            foreach ($children as $cid) {
                if (@IPS_VariableExists($cid)) {
                    return (bool)GetValue($cid);
                }
            }
        }

        if (function_exists("KNX_RequestStatus")) {
            $res = @KNX_RequestStatus($id);
            if (is_bool($res)) return $res;
        }

        if (@IPS_VariableExists($id)) {
            return (bool)GetValue($id);
        }

        return false;
    }
}
