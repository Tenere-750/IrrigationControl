<?php

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean("MasterSwitch", false);
        $this->RegisterPropertyString("ZoneList", "[]");

        // Timer mit Prefix IRR registrieren
        $this->RegisterTimer("ZoneAction", 0, "IRR_ZoneTimer(\$_IPS['TARGET']);");
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
            IPS_LogMessage("IRR", "Zonenliste ungültig.");
            return;
        }

        foreach ($zones as $zone) {
            if (!IPS_VariableExists($zone["Ventil"])) {
                IPS_LogMessage("IRR", "Ungültige Ventil-ID in Zone: " . $zone["Name"]);
            }
            if (!IPS_VariableExists($zone["Pumpe"])) {
                IPS_LogMessage("IRR", "Ungültige Pumpen-ID in Zone: " . $zone["Name"]);
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
            IPS_LogMessage("IRR", "Zone nicht vorhanden: " . $zoneIndex);
            return;
        }

        $zone = $zones[$zoneIndex];

        if ($state) {
            // ------------------ ZONE EIN ------------------
            KNX_WriteDPT1($zone["Ventil"], true);

            // Timer starten
            $this->SetBuffer("PendingPumpOn", $zone["Pumpe"]);
            $this->SetTimerInterval("ZoneAction", $zone["Verfahrzeit"]);
        } else {
            // ------------------ ZONE AUS ------------------
            if (!$this->IsAnyZoneActiveExcept($zoneIndex)) {
                KNX_WriteDPT1($zone["Pumpe"], false);
            }

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

        $this->SetTimerInterval("ZoneAction", 0);
    }

    private function IsAnyZoneActiveExcept($index)
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);

        foreach ($zones as $i => $zone) {
            if ($i == $index) continue;
            if (GetValue($zone["Ventil"])) {
                return true;
            }
        }
        return false;
    }
}
