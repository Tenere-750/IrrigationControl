<?php
declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("MasterSwitchID", 0);
        $this->RegisterPropertyInteger("PumpID", 0);
        $this->RegisterPropertyInteger("ValveTravelTime", 7);

        $zones = [];
        for ($i = 1; $i <= 7; $i++) {
            $zones[] = [
                "Name" => "Zone $i",
                "ValveID" => 0
            ];
        }
        $this->RegisterPropertyString("Zones", json_encode($zones));

        $this->SetBuffer("Manual", json_encode(array_fill(0, 7, false)));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

        $zones = json_decode($this->ReadPropertyString("Zones"), true);
        $manual = json_decode($this->GetBuffer("Manual"), true);

        foreach ($form["elements"] as &$element) {
            if ($element["name"] === "Zones") {
                $element["values"] = $zones;
            }

            if ($element["name"] === "ManualZones") {
                $rows = [];
                foreach ($zones as $i => $z) {
                    $rows[] = [
                        "Index"    => $i,
                        "Name"     => $z["Name"],
                        "Selected" => (bool)$manual[$i]
                    ];
                }
                $element["values"] = $rows;
            }
        }

        return json_encode($form);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "ToggleZone":
                $this->UpdateManualSelection($Value);
                break;

            case "OpenSelectedZones":
                $this->OpenSelectedZones();
                break;

            case "CloseSelectedZones":
                $this->CloseSelectedZones();
                break;

            case "PumpOn":
                $this->Pump(true);
                break;

            case "PumpOff":
                $this->Pump(false);
                break;

            case "MasterOn":
                $this->Master(true);
                break;

            case "MasterOff":
                $this->Master(false);
                break;
        }
    }

    private function UpdateManualSelection($jsonRow)
    {
        $row = json_decode($jsonRow, true);

        $manual = json_decode($this->GetBuffer("Manual"), true);
        $manual[(int)$row["Index"]] = (bool)$row["Selected"];

        $this->SetBuffer("Manual", json_encode($manual));
    }

    private function Pump(bool $state)
    {
        $id = $this->ReadPropertyInteger("PumpID");
        if ($id > 0) {
            EIB_Switch($id, $state);
        }
    }

    private function Master(bool $state)
    {
        $id = $this->ReadPropertyInteger("MasterSwitchID");
        if ($id > 0) {
            SetValueBoolean($id, $state);
        }
    }

    private function OpenSelectedZones()
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);
        $manual = json_decode($this->GetBuffer("Manual"), true);

        $travel = $this->ReadPropertyInteger("ValveTravelTime");

        $this->Pump(true);
        IPS_Sleep($travel * 1000);

        foreach ($zones as $i => $z) {
            if ($manual[$i] && $z["ValveID"] > 0) {
                EIB_Switch($z["ValveID"], true);
            }
        }
    }

    private function CloseSelectedZones()
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);
        $manual = json_decode($this->GetBuffer("Manual"), true);

        foreach ($zones as $i => $z) {
            if ($manual[$i] && $z["ValveID"] > 0) {
                EIB_Switch($z["ValveID"], false);
            }
        }

        IPS_Sleep($this->ReadPropertyInteger("ValveTravelTime") * 1000);

        $this->Pump(false);
    }
}
