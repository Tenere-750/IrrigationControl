<?php

class Bewaesserung extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean("MasterSwitch", false);
        $this->RegisterPropertyString("ZoneList", "[]");

        $this->RegisterTimer("ZoneAction", 0, "BEW_ZoneTimer(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ValidateInstances();
    }

    private function ValidateInstances()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones)) {
            IPS_LogMessage("BEW", "Zonenliste ungültig.");
            return;
        }

        foreach ($zones as $zone) {
            if (!IPS_VariableExists($zone["Ventil"])) {
                IPS_LogMessage("BEW", "Ungültige Ventil-ID in Zone: " . $zone["Name"]);
            }
            if (!IPS_VariableExists($zone["Pumpe"])) {
                IPS_LogMessage("BEW", "Ungültige Pumpen-ID in Zone: " . $zone["Name"]);
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident == "MasterSwitch") {
            $this->SetValue("MasterSwitch", $Value);
            return;
        }
    }

    public function UpdateForm()
    {
        return json_decode($this->ReadPropertyString("ZoneList"), true);
    }

    // -------------------------------------------------------------
    // -------------------- Steuerlogik -----------------------------
    // -------------------------------------------------------------

    public function SwitchZone($zoneIndex, $state)
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!isset($zones[$zoneIndex])) {
            IPS_LogMessage("BEW", "Zone nicht vorhanden: " . $zoneIndex);
            return;
        }

        $zone = $zones[$zoneIndex];

        if ($state) {
            // ------------------ ZONE EIN ------------------
            // 1. Ventil EIN
            KNX_WriteDPT1($zone["Ventil"], true);

            // 2. Nach Verfahrzeit Pumpe EIN
            $this->SetBuffer("PendingPumpOn", $zone["Pumpe"]);
            $this->SetTimerInterval("ZoneAction", $zone["Verfahrzeit"]);
        } else {
            // ------------------ ZONE AUS ------------------
            // Erst Pumpe ausschalten, wenn dies die letzte aktive Zone war
            if (!$this->IsAnyZoneActiveExcept($zoneIndex)) {
                KNX_WriteDPT1($zone["Pumpe"], false);
            }

            // Ventil AUS
            KNX_WriteDPT1($zone["Ventil"], false);
        }
    }

    public function ZoneTimer()
    {
        $pumpID = $this->GetBuffer("PendingPumpOn");
        if ($pumpID != "") {
            KNX_WriteDPT1($pumpID, true);
            $this->SetBuffer("PendingPumpOn", "");
        }

        // Timer deaktivieren
        $this->SetTimerInterval("ZoneAction", 0);
    }

    private function IsAnyZoneActiveExcept($index)
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);

        foreach ($zones as $i => $zone) {
            if ($i == $index) continue;
            $value = GetValue($zone["Ventil"]);
            if ($value) return true;
        }

        return false;
    }
}
