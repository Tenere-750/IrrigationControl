<?php

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("PumpInstance", 0);
        $this->RegisterPropertyInteger("MasterSwitch", 0);
        $this->RegisterPropertyString("Zones", "[]");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }


    // --------------------------------------------------------
    // MANUELLES SCHALTEN EINER ZONE
    // --------------------------------------------------------
    public function ManuellZoneSwitch(int $ZoneIndex, bool $State)
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        if (!isset($zones[$ZoneIndex])) {
            IPS_LogMessage("Irrigation", "Zone $ZoneIndex existiert nicht");
            return;
        }

        $zone = $zones[$ZoneIndex];
        $valve = intval($zone["ValveInstance"]);

        if ($valve <= 0) {
            IPS_LogMessage("Irrigation", "Zone hat keine Ventil-Instanz");
            return;
        }

        // Ventil schalten
        KNX_WriteDPT1($valve, $State);

        // Pumpe nur einschalten, wenn ein Ventil aktiv ist
        $this->UpdatePumpState();
    }


    // --------------------------------------------------------
    // PUMPE AKTUALISIEREN
    // --------------------------------------------------------
    private function UpdatePumpState()
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);
        $pump = $this->ReadPropertyInteger("PumpInstance");

        if ($pump <= 0) {
            return;
        }

        $anyValveOn = false;

        foreach ($zones as $zone) {
            if (isset($zone["ValveInstance"]) && intval($zone["ValveInstance"]) > 0) {

                $state = GetValue(IPS_GetObjectIDByName("Status", $zone["ValveInstance"]));

                if ($state) {
                    $anyValveOn = true;
                    break;
                }
            }
        }

        KNX_WriteDPT1($pump, $anyValveOn);
    }


    // --------------------------------------------------------
    // ALLE VENTILE UND PUMPE AUSSCHALTEN
    // --------------------------------------------------------
    public function SwitchAllOff()
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);
        $pump = $this->ReadPropertyInteger("PumpInstance");

        foreach ($zones as $zone) {
            if (intval($zone["ValveInstance"]) > 0) {
                KNX_WriteDPT1(intval($zone["ValveInstance"]), false);
            }
        }

        if ($pump > 0) {
            KNX_WriteDPT1($pump, false);
        }
    }
}
