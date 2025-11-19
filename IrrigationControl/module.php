<?php

declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("MasterID", 0);
        $this->RegisterPropertyInteger("PumpID", 0);
        $this->RegisterPropertyInteger("GlobalTravelTime", 1);
        $this->RegisterPropertyString("ZoneList", "[]");

        $this->RegisterTimer("PumpOnDelay", 0, "IRR_PumpOnTimer(\$_IPS['TARGET']);");

        $this->SetBuffer("ActiveZones", "0");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->ValidateConfig();
    }

    private function ValidateConfig()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);

        if (!is_array($zones)) {
            IPS_LogMessage("IRR", "ZoneList ungültig");
            return;
        }

        foreach ($zones as $z) {
            if (isset($z["Ventil"]) && $z["Ventil"] > 0 && !IPS_InstanceExists($z["Ventil"])) {
                IPS_LogMessage("IRR", "Ungültiges Ventil in Zone: " . ($z["Name"] ?? "?"));
            }
        }

        if ($this->ReadPropertyInteger("PumpID") > 0 &&
            !IPS_InstanceExists($this->ReadPropertyInteger("PumpID"))) {
            IPS_LogMessage("IRR", "Ungültige Pumpeninstanz");
        }

        if ($this->ReadPropertyInteger("MasterID") > 0 &&
            !IPS_InstanceExists($this->ReadPropertyInteger("MasterID"))) {
            IPS_LogMessage("IRR", "Ungültige Masterinstanz");
        }
    }

    // ----------------------------
    // RequestAction
    // ----------------------------
    public function RequestAction($Ident, $Value)
    {
        if (str_starts_with($Ident, "Zone")) {
            $zoneIndex = intval(substr($Ident, 4));
            $this->SwitchZone($zoneIndex, $Value);
            return;
        }

        if ($Ident === "Pump") {
            $this->ManualPump($Value);
            return;
        }

        if ($Ident === "Master") {
            $this->Master($Value);
            return;
        }
    }

    private function Master(bool $state)
    {
        $id = $this->ReadPropertyInteger("MasterID");
        if ($id > 0) {
            KNX_WriteDPT1($id, $state);
        }
    }

    private function ManualPump(bool $state)
    {
        $pump = $this->ReadPropertyInteger("PumpID");
        if ($pump > 0) {
            KNX_WriteDPT1($pump, $state);
        }
    }


    // ----------------------------
    // Zone EIN/AUS
    // ----------------------------
    public function SwitchZone(int $zoneIndex, bool $state)
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);

        if (!isset($zones[$zoneIndex])) {
            IPS_LogMessage("IRR", "Zone $zoneIndex existiert nicht");
            return;
        }

        $zone = $zones[$zoneIndex];
        $ventil = intval($zone["Ventil"]);

        $travelSeconds = intval($zone["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
        $travelMs = $travelSeconds * 1000;

        $pump = $this->ReadPropertyInteger("PumpID");

        if ($ventil <= 0 || $pump <= 0) {
            IPS_LogMessage("IRR", "Zone hat keine gültige Ventil-/Pumpeninstanz");
            return;
        }

        $active = intval($this->GetBuffer("ActiveZones"));

        if ($state) {

            // Ventil öffnen
            KNX_WriteDPT1($ventil, true);

            // aktive Zonen erhöhen
            $active++;
            $this->SetBuffer("ActiveZones", strval($active));

            if ($active === 1) {
                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnDelay", $travelMs);
            }

        } else {

            // Ventil schließen
            KNX_WriteDPT1($ventil, false);

            $active--;
            if ($active < 0) {
                $active = 0;
            }
            $this->SetBuffer("ActiveZones", strval($active));

            if ($active === 0) {
                KNX_WriteDPT1($pump, false);
            }
        }
    }

    // ----------------------------
    // Timer: Pumpe EIN
    // ----------------------------
    public function PumpOnTimer()
    {
        $pending = intval($this->GetBuffer("PumpOnPending"));

        if ($pending === 1) {
            $pump = $this->ReadPropertyInteger("PumpID");
            if ($pump > 0) {
                KNX_WriteDPT1($pump, true);
            }
        }

        $this->SetTimerInterval("PumpOnDelay", 0);
        $this->SetBuffer("PumpOnPending", "0");
    }


    // ---------------------------------------------------------
    // Weitere steuerbare Funktionen
    // ---------------------------------------------------------

    public function Pump(bool $state)
    {
        $pump = $this->ReadPropertyInteger("PumpID");
        if ($pump > 0) {
            KNX_WriteDPT1($pump, $state);
        }
    }

    public function AllOff()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        $pump = $this->ReadPropertyInteger("PumpID");

        KNX_WriteDPT1($pump, false);

        foreach ($zones as $z) {
            if ($z["Ventil"] > 0) {
                KNX_WriteDPT1($z["Ventil"], false);
            }
        }

        $this->SetBuffer("ActiveZones", "0");
    }

    public function GetZones()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        $pump = $this->ReadPropertyInteger("PumpID");

        $status = [];
        foreach ($zones as $i => $z) {
            $status[] = [
                "index" => $i,
                "name"  => $z["Name"],
                "active" => ($z["Ventil"] > 0) ? GetValue($z["Ventil"]) : false
            ];
        }

        return [
            "pump" => ($pump > 0) ? GetValue($pump) : false,
            "zones" => $status
        ];
    }
}
