<?php
declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("PumpInstance", 0);
        $this->RegisterPropertyInteger("MasterSwitch", 0);
        $this->RegisterPropertyString("Zones", json_encode([]));

        // Puffer für manuelle Zustände
        $this->SetBuffer("ManualStates", json_encode([]));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Wenn Zonen geändert wurden, den Manual-Puffer neu anlegen
        $zones = json_decode($this->ReadPropertyString("Zones"), true);
        $manual = array_fill(0, count($zones), false);
        $this->SetBuffer("ManualStates", json_encode($manual));
    }

    public function GetConfigurationForm()
    {
        return json_encode(json_decode(file_get_contents(__DIR__ . "/form.json"), true));
    }


    // ==========================================================
    // REQUEST ACTION HANDLER
    // ==========================================================

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {

            case "ManuellZoneSwitch":
                $this->ManualZoneSwitch($Value);
                break;

            case "SwitchAllOff":
                $this->SwitchAllOff();
                break;

            default:
                echo "Unbekannte RequestAction: $Ident";
        }
    }


    // ==========================================================
    // MANUELLES SCHALTEN EINER ZONE
    // ==========================================================

    private function ManualZoneSwitch($payload)
    {
        $data = json_decode($payload, true);

        if (!isset($data["Index"]) || !isset($data["State"])) {
            $this->SendDebug("ManualZoneSwitch", "Ungültiges Payload", 0);
            return;
        }

        $index = (int)$data["Index"];
        $state = (bool)$data["State"];

        $zones = json_decode($this->ReadPropertyString("Zones"), true);
        $manual = json_decode($this->GetBuffer("ManualStates"), true);

        if (!isset($zones[$index])) {
            $this->SendDebug("ManualZoneSwitch", "Zone $index existiert nicht", 0);
            return;
        }

        $valveID = (int)$zones[$index]["ValveInstance"];

        if ($valveID > 0) {
            KNX_WriteDPT1($valveID, $state);
            IPS_Sleep(200);
        }

        // Wenn eine Zone AN geschaltet wird: Pumpe einschalten
        if ($state) {
            $this->Pump(true);
        }

        // Wenn AUS, prüfen ob überhaupt noch eine Zone aktiv ist
        if (!$state) {
            $manual[$index] = false;
            $this->SetBuffer("ManualStates", json_encode($manual));

            if (!$this->AnyZoneActive($manual)) {
                $this->Pump(false);
            }

            return;
        }

        // Status puffern
        $manual[$index] = $state;
        $this->SetBuffer("ManualStates", json_encode($manual));
    }


    private function AnyZoneActive(array $states): bool
    {
        foreach ($states as $s) {
            if ($s === true) return true;
        }
        return false;
    }


    // ==========================================================
    // ALLES AUSSCHALTEN
    // ==========================================================

    private function SwitchAllOff()
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        foreach ($zones as $z) {
            if ((int)$z["ValveInstance"] > 0) {
                KNX_WriteDPT1((int)$z["ValveInstance"], false);
                IPS_Sleep(150);
            }
        }

        $this->Pump(false);

        $manual = array_fill(0, count($zones), false);
        $this->SetBuffer("ManualStates", json_encode($manual));
    }


    // ==========================================================
    // HILFSMETHODEN
    // ==========================================================

    private function Pump(bool $state)
    {
        $id = $this->ReadPropertyInteger("PumpInstance");
        if ($id > 0) {
            KNX_WriteDPT1($id, $state);
        }
    }
}
