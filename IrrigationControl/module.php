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
        $this->RegisterPropertyString("ZoneList", "[]");

        // Timer
        $this->RegisterTimer("PumpOnDelay", 0, "IRR_PumpOnTimer(\$_IPS['TARGET']);");

        // interne Statuswerte
        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterWebFrontVariables();
    }

    private function RegisterWebFrontVariables()
    {
        // MASTER
        $this->RegisterVariableBoolean("Master", "Master EIN/AUS", "~Switch");
        $this->EnableAction("Master");

        // PUMPE
        $this->RegisterVariableBoolean("Pump", "Pumpe EIN/AUS", "~Switch");
        $this->EnableAction("Pump");

        // ZONEN
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones)) {
            return;
        }

        foreach ($zones as $i => $zone) {
            $ident = "Zone" . $i;
            $this->RegisterVariableBoolean($ident, $zone["Name"], "~Switch");
            $this->EnableAction($ident);
        }
    }

    // ====================================================================
    // WEBFRONT ACTIONS
    // ====================================================================
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === "Master") {
            $this->Master($Value);
            SetValue($this->GetIDForIdent("Master"), $Value);
            return;
        }

        if ($Ident === "Pump") {
            $this->Pump($Value);
            SetValue($this->GetIDForIdent("Pump"), $Value);
            return;
        }

        if (str_starts_with($Ident, "Zone")) {
            $index = intval(substr($Ident, 4));
            $this->SwitchZone($index, $Value);
            SetValue($this->GetIDForIdent($Ident), $Value);
            return;
        }
    }

    // ====================================================================
    // MASTER
    // ====================================================================
    private function Master(bool $state)
    {
        $id = $this->ReadPropertyInteger("MasterID");
        if ($id > 0) {
            KNX_WriteDPT1($id, $state);
        }

        if ($state) {
            $this->AllOff();
        }
    }

    // ====================================================================
    // PUMPE
    // ====================================================================
    private function Pump(bool $state)
    {
        $id = $this->ReadPropertyInteger("PumpID");
        if ($id > 0) {
            KNX_WriteDPT1($id, $state);
        }
    }

    // ====================================================================
    // ALLE AUS
    // ====================================================================
    private function AllOff()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);

        foreach ($zones as $i => $z) {
            if ($z["Ventil"] > 0) {
                KNX_WriteDPT1($z["Ventil"], false);
                SetValue($this->GetIDForIdent("Zone" . $i), false);
            }
        }

        $pump = $this->ReadPropertyInteger("PumpID");
        if ($pump > 0) {
            KNX_WriteDPT1($pump, false);
        }

        $this->SetBuffer("ActiveZones", "0");
    }

    // ====================================================================
    // ZONE EIN/AUS
    // ====================================================================
    private function SwitchZone(int $zoneIndex, bool $state)
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);

        if (!isset($zones[$zoneIndex])) {
            IPS_LogMessage("IRR", "Ungültige Zone: $zoneIndex");
            return;
        }

        $zone = $zones[$zoneIndex];
        $ventil = intval($zone["Ventil"]);
        $pump   = $this->ReadPropertyInteger("PumpID");

        // MASTER blockiert
        if (GetValue($this->GetIDForIdent("Master")) === true) {
            IPS_LogMessage("IRR", "Blockiert durch Master.");
            return;
        }

        $active = intval($this->GetBuffer("ActiveZones"));

        // ============= EIN =====================
        if ($state) {

            KNX_WriteDPT1($ventil, true);
            $active++;
            $this->SetBuffer("ActiveZones", strval($active));

            if ($active === 1) {
                $travel = intval($zone["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnDelay", $travel * 1000);
            }
        }

        // ============= AUS =====================
        else {

            KNX_WriteDPT1($ventil, false);
            $active--;
            if ($active < 0) $active = 0;
            $this->SetBuffer("ActiveZones", strval($active));

            if ($active === 0) {
                KNX_WriteDPT1($pump, false);
            }
        }
    }

    // ====================================================================
    // TIMER
    // ====================================================================
    public function PumpOnTimer()
    {
        if ($this->GetBuffer("PumpOnPending") === "1") {

            $pump = $this->ReadPropertyInteger("PumpID");
            if ($pump > 0) {
                KNX_WriteDPT1($pump, true);
                SetValue($this->GetIDForIdent("Pump"), true);
            }
        }

        $this->SetTimerInterval("PumpOnDelay", 0);
        $this->SetBuffer("PumpOnPending", "0");
    }

    // ====================================================================
    // GET ZONES (API)
    // ====================================================================
    public function GetZones()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        $out = [];

        foreach ($zones as $i => $z) {
            $out[] = [
                "Name"   => $z["Name"],
                "State"  => GetValue($this->GetIDForIdent("Zone" . $i)),
                "Ventil" => $z["Ventil"]
            ];
        }

        return $out;
    }
}
