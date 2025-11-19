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

        $this->RegisterTimer("PumpDelayTimer", 0, "IIRC_PumpDelayExecute(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {

            case "PumpOn":
                $this->PumpOn();
                break;

            case "PumpOff":
                $this->PumpOff();
                break;

            case "AllZonesOff":
                $this->AllZonesOff();
                break;

            case "ManualZoneClick":
                $this->ManualZoneClick($Value);
                break;
        }
    }

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

    public function PumpDelayExecute()
    {
        $this->PumpOn();
        $this->SetTimerInterval("PumpDelayTimer", 0);
    }

    private function StartPumpDelay()
    {
        $time = $this->ReadPropertyInteger("ValveTime");
        $this->SetTimerInterval("PumpDelayTimer", $time * 1000);
    }

    public function AllZonesOff()
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        foreach ($zones as $zone) {
            $this->writeKNX($zone["ValveInstance"], false);
        }

        $this->PumpOff();
    }

    public function ManualZoneClick($json)
    {
        $row = json_decode($json, true);
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        $index = $row["Index"];
        $col = $row["Column"]; // Name der Spalte

        if (!isset($zones[$index]))
            return;

        switch ($col) {

            case "BtnOn":
                $this->ManualZoneOn($index);
                break;

            case "BtnOff":
                $this->ManualZoneOff($index);
                break;
        }
    }

    public function ManualZoneOn($index)
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        if (!isset($zones[$index]))
            return;

        $valve = $zones[$index]["ValveInstance"];

        $this->writeKNX($valve, true);

        $this->StartPumpDelay();
    }

    public function ManualZoneOff($index)
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        if (!isset($zones[$index]))
            return;

        $valve = $zones[$index]["ValveInstance"];

        $this->writeKNX($valve, false);

        IPS_Sleep(300);

        if ($this->AllValvesClosed())
            $this->PumpOff();
    }

    private function AllValvesClosed()
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        foreach ($zones as $zone) {

            $inst = $zone["ValveInstance"];

            if (!$this->isValidInstance($inst))
                continue;

            $var = @IPS_GetObjectIDByIdent("Value", $inst);

            if ($var && GetValueBoolean($var) === true)
                return false;
        }

        return true;
    }
}
