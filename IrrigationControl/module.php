<?php
declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("MasterID", 0);
        $this->RegisterPropertyInteger("PumpID", 0);
        $this->RegisterPropertyInteger("GlobalTravelTime", 7);
        $this->RegisterPropertyString("ZoneList", "[]");

        $this->RegisterTimer("PumpOnTimer", 0, "IRR_PumpOnTimer(\$_IPS['TARGET']);");

        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterWebFrontVariables();

        $this->SetTimerInterval("PumpOnTimer", 0);
        $this->SetBuffer("PumpOnPending", "0");

        if ($this->GetBuffer("ActiveZones") === "") {
            $this->SetBuffer("ActiveZones", "0");
        }
    }

    private function RegisterWebFrontVariables(): void
    {
        if (@IPS_GetObjectIDByIdent("Master", $this->InstanceID) === false) {
            $this->RegisterVariableBoolean("Master", "Master", "~Switch");
        }
        $this->EnableAction("Master");

        if (@IPS_GetObjectIDByIdent("Pump", $this->InstanceID) === false) {
            $this->RegisterVariableBoolean("Pump", "Pumpe", "~Switch");
        }
        $this->EnableAction("Pump");

        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones)) return;

        foreach ($zones as $i => $zone) {
            $ident = "Zone" . $i;

            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
                $this->RegisterVariableBoolean($ident, $zone["Name"] ?? ("Zone " . ($i + 1)), "~Switch");
            } else {
                $vid = $this->GetIDForIdent($ident);
                if ($vid && isset($zone["Name"])) {
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

            $masterID = $this->ReadPropertyInteger("MasterID");
            $real = $this->ReadMasterState($masterID);

            SetValue($this->GetIDForIdent("Master"), $real);
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
        $masterID = $this->ReadPropertyInteger("MasterID");
        if ($masterID > 0 && IPS_InstanceExists($masterID)) {
            KNX_WriteDPT1($masterID, $state);
        }

        if ($state === true) {
            $this->AllOff();
        }
    }

    public function Pump(bool $state): void
    {
        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
            KNX_WriteDPT1($pumpID, $state);
        }
    }

    public function AllOff(): void
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones)) $zones = [];

        foreach ($zones as $i => $z) {
            $ventil = intval($z["Ventil"] ?? 0);
            if ($ventil > 0 && IPS_InstanceExists($ventil)) {
                KNX_WriteDPT1($ventil, false);
            }

            $vid = $this->GetIDForIdent("Zone" . $i);
            if ($vid) SetValue($vid, false);
        }

        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
            KNX_WriteDPT1($pumpID, false);
        }

        $pvid = $this->GetIDForIdent("Pump");
        if ($pvid) SetValue($pvid, false);

        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);
    }

    public function SwitchZone(int $zoneIndex, bool $state): bool
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!isset($zones[$zoneIndex])) return false;

        // 🔥 Master sicher prüfen
        $masterID = $this->ReadPropertyInteger("MasterID");
        if ($this->ReadMasterState($masterID) === true) {
            IPS_LogMessage("IrrigationControl", "Master aktiv – Zone $zoneIndex blockiert.");
            return false;
        }

        $zone = $zones[$zoneIndex];
        $ventil = intval($zone["Ventil"] ?? 0);
        $pumpID = $this->ReadPropertyInteger("PumpID");

        if ($ventil <= 0 || !IPS_InstanceExists($ventil)) return false;
        if ($pumpID <= 0 || !IPS_InstanceExists($pumpID)) return false;

        $active = intval($this->GetBuffer("ActiveZones"));

        if ($state) {
            KNX_WriteDPT1($ventil, true);

            $active++;
            $this->SetBuffer("ActiveZones", strval($active));

            if ($active === 1) {
                $travel = intval($zone["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
                if ($travel < 0) $travel = 0;

                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnTimer", $travel * 1000);
            }

            return true;
        }

        KNX_WriteDPT1($ventil, false);

        $active--;
        if ($active < 0) $active = 0;
        $this->SetBuffer("ActiveZones", strval($active));

        if ($active === 0) {
            KNX_WriteDPT1($pumpID, false);

            $pvid = $this->GetIDForIdent("Pump");
            if ($pvid) SetValue($pvid, false);

            $this->SetBuffer("PumpOnPending", "0");
            $this->SetTimerInterval("PumpOnTimer", 0);
        }

        return true;
    }

    public function PumpOnTimer(): void
    {
        if ($this->GetBuffer("PumpOnPending") === "1") {
            $pumpID = $this->ReadPropertyInteger("PumpID");
            if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
                KNX_WriteDPT1($pumpID, true);

                $pvid = $this->GetIDForIdent("Pump");
                if ($pvid) SetValue($pvid, true);
            }
        }

        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);
    }

    public function GetZones(): array
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        $result = [];

        if (!is_array($zones)) return $result;

        foreach ($zones as $i => $z) {
            $result[] = [
                "Index"  => $i,
                "Name"   => $z["Name"] ?? ("Zone " . ($i + 1)),
                "State"  => GetValue($this->GetIDForIdent("Zone" . $i)),
                "Ventil" => $z["Ventil"] ?? 0
            ];
        }
        return $result;
    }

    private function ReadMasterState(int $masterID): bool
    {
        if ($masterID <= 0 || !IPS_InstanceExists($masterID)) {
            return false;
        }

        // 1️⃣ Statusvariable der KNX-Instanz lesen
        $children = IPS_GetChildrenIDs($masterID);
        foreach ($children as $cid) {
            if (IPS_VariableExists($cid)) {
                return (bool)GetValue($cid);
            }
        }

        // 2️⃣ KNX_RequestStatus nur wenn sinnvoll
        if (function_exists('KNX_RequestStatus')) {
            $res = @KNX_RequestStatus($masterID);
            if (is_bool($res)) return $res;
        }

        // 3️⃣ Master nicht vorhanden → sicher false
        return false;
    }
}
