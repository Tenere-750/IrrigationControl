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
        $this->RegisterPropertyInteger("GlobalTravelTime", 7); // Sekunden
        $this->RegisterPropertyString("ZoneList", "[]");

        // Timer für verzögertes Pumpen-Einschalten
        $this->RegisterTimer("PumpOnDelay", 0, "IRR_PumpOnTimer(\$_IPS['TARGET']);");

        // Buffer für aktive Zonen
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
        // ----- MASTER -----
        $this->RegisterVariableBoolean("Master", "Master", "~Switch");
        $this->EnableAction("Master");

        // ----- PUMPE -----
        $this->RegisterVariableBoolean("Pump", "Pumpe", "~Switch");
        $this->EnableAction("Pump");

        // ----- ZONEN -----
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

    // ====================================================================================
    // WEBFRONT REQUEST ACTION
    // ====================================================================================
    public function RequestAction($Ident, $Value)
    {
        // --- Master ---
        if ($Ident === "Master") {
            $this->IRR_Master($Value);
            SetValue($this->GetIDForIdent("Master"), $Value);
            return;
        }

        // --- Pumpe ---
        if ($Ident === "Pump") {
            $this->IRR_Pump($Value);
            SetValue($this->GetIDForIdent("Pump"), $Value);
            return;
        }

        // --- Zonen ---
        if (str_starts_with($Ident, "Zone")) {
            $index = intval(substr($Ident, 4));
            $this->IRR_SwitchZone($index, $Value);
            SetValue($this->GetIDForIdent($Ident), $Value);
            return;
        }
    }

    // ====================================================================================
    // MASTER FUNKTION
    // ====================================================================================
    public function IRR_Master(bool $state)
    {
        $master = $this->ReadPropertyInteger("MasterID");
        $pump   = $this->ReadPropertyInteger("PumpID");

        if ($master > 0) {
            KNX_WriteDPT1($master, $state);
        }

        if ($state === true) {
            // MASTER EIN = Not-Aus
            $this->IRR_AllOff();
        }
    }

    // ====================================================================================
    // PUMPE MANUELL
    // ====================================================================================
    public function IRR_Pump(bool $state)
    {
        $pump = $this->ReadPropertyInteger("PumpID");
        if ($pump > 0) {
            KNX_WriteDPT1($pump, $state);
        }
    }

    // ====================================================================================
    // ALLE AUS
    // ====================================================================================
    public function IRR_AllOff()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);

        // Alle Ventile zu
        foreach ($zones as $i => $z) {
            if ($z["Ventil"] > 0) {
                KNX_WriteDPT1($z["Ventil"], false);
                SetValue($this->GetIDForIdent("Zone" . $i), false);
            }
        }

        // Pumpe aus
        $pump = $this->ReadPropertyInteger("PumpID");
        if ($pump > 0) {
            KNX_WriteDPT1($pump, false);
        }

        // Status zurücksetzen
        $this->SetBuffer("ActiveZones", "0");
    }

    // ====================================================================================
    // ZONEN STEUERUNG
    // ====================================================================================
    public function IRR_SwitchZone(int $zoneIndex, bool $state)
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);

        if (!isset($zones[$zoneIndex])) {
            IPS_LogMessage("IRR", "Ungültige Zone: $zoneIndex");
            return;
        }

        $zone = $zones[$zoneIndex];
        $ventil = intval($zone["Ventil"]);
        $pump   = $this->ReadPropertyInteger("PumpID");

        $master = GetValue($this->GetIDForIdent("Master"));
        if ($master === true) {
            IPS_LogMessage("IRR", "Blockiert durch Master-Switch!");
            return;
        }

        if ($ventil <= 0 || $pump <= 0) {
            IPS_LogMessage("IRR", "Zone oder Pumpe ungültig");
            return;
        }

        $active = intval($this->GetBuffer("ActiveZones"));

        // ====================================================================================
        // ZONE EIN
        // ====================================================================================
        if ($state === true) {

            KNX_WriteDPT1($ventil, true);

            // Zonenstatus erhöhen
            $active++;
            $this->SetBuffer("ActiveZones", strval($active));

            // WENN dies die erste aktive Zone ist → Pumpe verzögert einschalten
            if ($active === 1) {
                $travelTimeSec = intval($zone["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
                $delayMs = $travelTimeSec * 1000;

                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnDelay", $delayMs);
            }
        }

        // ====================================================================================
        // ZONE AUS
        // ====================================================================================
        else {

            KNX_WriteDPT1($ventil, false);

            $active--;
            if ($active < 0) { $active = 0; }
            $this->SetBuffer("ActiveZones", strval($active));

            // Wenn keine Zone mehr aktiv → Pumpe aus
            if ($active === 0) {
                KNX_WriteDPT1($pump, false);
            }
        }
    }

    // ====================================================================================
    // TIMER: NACH VERFAHRZEIT PUMPE EINSCHALTEN
    // ====================================================================================
    public function IRR_PumpOnTimer()
    {
        if ($this->GetBuffer("PumpOnPending") === "1") {

            $pump = $this->ReadPropertyInteger("PumpID");
            if ($pump > 0) {
                KNX_WriteDPT1($pump, true);
                SetValue($this->GetIDForIdent("Pump"), true);
            }
        }

        // Reset
        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnDelay", 0);
    }

    // ====================================================================================
    // INFORMATION ZU ZONEN
    // ====================================================================================
    public function IRR_GetZones()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        $result = [];

        foreach ($zones as $i => $z) {

            $result[] = [
                "Name"   => $z["Name"],
                "State"  => GetValue($this->GetIDForIdent("Zone" . $i)),
                "Ventil" => $z["Ventil"]
            ];
        }

        return $result;
    }
}
