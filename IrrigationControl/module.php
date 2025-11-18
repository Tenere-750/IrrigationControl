<?php
declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("PumpInstance", 0);
        $this->RegisterPropertyInteger("MasterSwitch", 0);
        $this->RegisterPropertyInteger("ValveTravelTime", 7);

        $this->RegisterPropertyString("Zones", json_encode([]));
        $this->SetBuffer("ManualStates", json_encode([]));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $zones = json_decode($this->ReadPropertyString("Zones"), true);
        $manualStates = array_fill(0, count($zones), false);
        $this->SetBuffer("ManualStates", json_encode($manualStates));
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . "/form.json"), true);

        $zones = json_decode($this->ReadPropertyString("Zones"), true);
        $manual = json_decode($this->GetBuffer("ManualStates"), true);

        foreach ($form["elements"] as &$panel) {
            if (!isset($panel["items"])) continue;

            foreach ($panel["items"] as &$item) {

                if ($item["type"] === "List" && $item["name"] === "Zones") {
                    $item["values"] = $zones;
                }

                if ($item["type"] === "List" && $item["name"] === "ManualZones") {
                    $rows = [];
                    foreach ($zones as $i => $z) {
                        $rows[] = [
                            "Index" => $i,
                            "Name" => $z["Name"],
                            "Selected" => $manual[$i] ?? false
                        ];
                    }
                    $item["values"] = $rows;
                }

            }
        }

        return json_encode($form);
    }


    // =========================================================================
    // REQUEST ACTIONS
    // =========================================================================

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
                $this->SendDebug("RequestAction", "Unbekannter Ident: $Ident", 0);
        }
    }


    // =========================================================================
    // MANUELLE ZONENSTEUERUNG
    // =========================================================================

    private function ManualZoneSwitch($payload)
    {
        $data = json_decode($payload, true);
        if (!is_array($data) || !isset($data["Index"]) || !isset($data["State"])) return;

        $index = (int)$data["Index"];
        $state = (bool)$data["State"];

        $zones = json_decode($this->ReadPropertyString("Zones"), true);
        $manual = json_decode($this->GetBuffer("ManualStates"), true);

        if (!isset($zones[$index])) return;

        $valveID = (int)$zones[$index]["ValveInstance"];

        if ($valveID > 0) {
            KNX_WriteDPT1($valveID, $state);
            IPS_Sleep(150);
        }

        $manual[$index] = $state;
        $this->SetBuffer("ManualStates", json_encode($manual));

        if ($state) {
            $this->Pump(true);
        } else {
            if (!$this->AnyZoneActive($manual)) {
                $this->Pump(false);
            }
        }
    }

    private function AnyZoneActive(array $states): bool
    {
        foreach ($states as $s) {
            if ($s === true) return true;
        }
        return false;
    }


    // =========================================================================
    // ALLES AUS
    // =========================================================================

    private function SwitchAllOff()
    {
        $zones = json_decode($this->ReadPropertyString("Zones"), true);

        foreach ($zones as $z) {
            if ((int)$z["ValveInstance"] > 0) {
                KNX_WriteDPT1((int)$z["ValveInstance"], false);
                IPS_Sleep(100);
            }
        }

        $this->Pump(false);

        $manual = array_fill(0, count($zones), false);
        $this->SetBuffer("ManualStates", json_encode($manual));
    }


    // =========================================================================
    // PUMPE
    // =========================================================================

    private function Pump(bool $state)
    {
        $id = $this->ReadPropertyInteger("PumpInstance");

        if ($id > 0) {
            KNX_WriteDPT1($id, $state);
        }
    }
}
