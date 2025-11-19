<?php

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("PumpInstance", 0);
        $this->RegisterPropertyInteger("MasterSwitch", 0);
        $this->RegisterPropertyInteger("ValveTime", 7);
        $this->RegisterPropertyString("Zones", "[]");

        // Timer für verzögertes Pumpeneinschalten
        $this->RegisterTimer("PumpDelayTimer", 0, "IIRC_PumpDelayExecute(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    /* ---------------------------------------------------------
     *  Utility
     * --------------------------------------------------------- */

    private function isValidInstance($id)
    {
        return ($id > 0 && IPS_InstanceExists($id));
    }

    private function writeKNX($id, $value)
    {
        if ($this->isValidInstance($id)) {
            KNX_WriteDPT1($id, $value);
        }
    }

    /* ---------------------------------------------------------
     *  Pumpensteuerung
     * --------------------------------------------------------- */

    public function PumpOn()
    {
        $pump = $this->ReadPropertyInteger("PumpInstance");
        $this->writeKNX($pump, true);
    }

    public function PumpOff()
    {
        $pump = $this->ReadPropertyInteger("PumpInstance");
        $this->writeKNX($pump, false);
    }

    /* ---------------------------------------------------------
     *  Verzögertes Pumpeneinschalten (nicht blockierend)
     * --------------------------------------------------------- */

    public function PumpDelayExecute()
    {
        $this->PumpOn();
        $this->SetTimerInterval("PumpDelayTimer", 0); // deaktivieren
    }

    private function startPumpDelay()
    {
        $valveTime = $this->ReadPropertyInteger("ValveTime");
        $this->SetTimerInterval("PumpDelayTimer", $valveTime * 1000);
    }

    /* ---------------------------------------------------------
     *  Alle Zonen ausschalten
     * --------------------------------------------------------- */

    public function AllZonesOff()
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        foreach ($zones as $zone) {
            if ($this->isValidInstance($zone["ValveInstance"])) {
                $this->writeKNX($zone["ValveInstance"], false);
            }
        }

        $this->PumpOff();
    }

    /* ---------------------------------------------------------
     *  Manuelle Steuerliste für UI
     * --------------------------------------------------------- */

    public function GetZoneControlList()
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);
        $list = [];

        foreach ($zones as $index => $zone) {
            $list[] = [
                "Index" => $index,
                "Name"  => $zone["Name"],
                "ButtonOn"  => "AN",
                "ButtonOff" => "AUS"
            ];
        }

        return $list;
    }

    /* ---------------------------------------------------------
     *  Zonenein
     * --------------------------------------------------------- */

    public function ManualZoneOn($index)
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        if (!isset($zones[$index]))
            return;

        $zone = $zones[$index];

        if (!$this->isValidInstance($zone["ValveInstance"]))
            return;

        // 1) Ventil öffnen
        $this->writeKNX($zone["ValveInstance"], true);

        // 2) Verzögerung starten → Pumpe erst nach Verfahrzeit einschalten
        $this->startPumpDelay();

        IPS_LogMessage("Irrigation", "Zone EIN: " . $zone["Name"]);
    }

    /* ---------------------------------------------------------
     *  Zonenaus
     * --------------------------------------------------------- */

    public function ManualZoneOff($index)
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        if (!isset($zones[$index]))
            return;

        $zone = $zones[$index];

        if (!$this->isValidInstance($zone["ValveInstance"]))
            return;

        // 1) Ventil schließen
        $this->writeKNX($zone["ValveInstance"], false);

        // 2) Prüfen ob andere Zonen noch offen sind
        IPS_Sleep(500); // KNX benötigt kurze Zeit zur Verarbeitung

        if ($this->allValvesClosed()) {
            $this->PumpOff();
        }

        IPS_LogMessage("Irrigation", "Zone AUS: " . $zone["Name"]);
    }

    private function allValvesClosed()
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        foreach ($zones as $zone) {
            $inst = $zone["ValveInstance"];

            if (!$this->isValidInstance($inst))
                continue;

            $statusVar = @IPS_GetObjectIDByIdent("Value", $inst);

            if ($statusVar && GetValueBoolean($statusVar) === true) {
                return false; // ein Ventil noch offen
            }
        }

        return true;
    }
}
