<?php

declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("MasterID", 0);
        $this->RegisterPropertyInteger("PumpID", 0);
        $this->RegisterPropertyInteger("GlobalTravelTime", 7);
        $this->RegisterPropertyString("ZoneList", "[]");

        $this->RegisterTimer("PumpOnDelay", 0, "IRR_PumpOnTimer(\$_IPS['TARGET']);");

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
        $this->RegisterVariableBoolean("Master", "Master", "~Switch");
        $this->EnableAction("Master");

        $this->RegisterVariableBoolean("Pump", "Pumpe", "~Switch");
        $this->EnableAction("Pump");

        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones)) return;

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
    // --- MASTER ---
    if ($Ident === "Master") {

        // KNX-Master schalten
        $this->Master($Value);

        // KNX-Masterstatus auslesen und im WebFront anzeigen
        $masterID = $this->ReadPropertyInteger("MasterID");
        if ($masterID > 0) {
            SetValue($this->GetIDForIdent("Master"), GetValue($masterID));
        }

        return;
    }

    // --- PUMPE ---
    if ($Ident === "Pump") {
        $this->Pump($Value);
        SetValue($this->GetIDForIdent("Pump"), $Value);
        return;
    }

    // --- ZONEN ---
    if (str_starts_with($Ident, "Zone")) {

        // Index aus "ZoneX" extrahieren
        $index = intval(substr($Ident, 4));

        // Zonensteuerung aufrufen
        $this->SwitchZone($index, $Value);

        // Anzeige im WebFront aktualisieren
        SetValue($this->GetIDForIdent($Ident), $Value);
        return;
    }
}


    // ====================================================================================
    // MASTER FUNKTION
    // ====================================================================================
    public function Master(bool $state)
    {
        $master = $this->ReadPropertyInteger("MasterID");

        if ($master > 0) {
            KNX_WriteDPT1($master, $state);
        }

        if ($state === true) {
            $this->AllOff();
        }
    }

    // ====================================================================================
    // PUMPE MANUELL
    // ====================================================================================
    public function Pump(bool $state)
    {
        $pump = $this->ReadPropertyInteger("PumpID");
        if ($pump > 0) {
            KNX_WriteDPT1($pump, $state);
        }
    }

    // ====================================================================================
    // ALLE AUS
    // ====================================================================================
    public function AllOff()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);

        foreach ($zones as $i => $z) {
            if ($z["Ventil"] > 0) {
                KNX_WriteDPT1($z["Ventil"], false);
                SetValue($this->GetIDForIdent("Zone" . $i), false);
            }
        }

        $pump = $this->ReadPropertyInteger("PumpID");
        if ($pump > 0) {
            KNX_WriteDPT1($pump, false);
        }

        $this->SetBuffer("ActiveZones", "0");
    }

    // ====================================================================================
    // ZONEN STEUERUNG
    // ====================================================================================
    public function SwitchZone(int $zoneIndex, bool $state)
{
    $zones = json_decode($this->ReadPropertyString("ZoneList"), true);

    if (!isset($zones[$zoneIndex])) {
        IPS_LogMessage("IRR", "Ungültige Zone: $zoneIndex");
        return;
    }

    // --- Masterzustand ausschliesslich aus KNX lesen ---
    $masterID = $this->ReadPropertyInteger("MasterID");
    $master = ($masterID > 0) ? KNX_RequestStatus($masterID) : false;

    // Master aktiv → ZONE BLOCKIERT
    if ($master === true) {
        IPS_LogMessage("IRR", "Blockiert durch Master-Switch! Zone $zoneIndex wurde NICHT geschaltet.");
        return;
    }

    $zone = $zones[$zoneIndex];
    $ventil = intval($zone["Ventil"]);
    $pump = $this->ReadPropertyInteger("PumpID");

    if ($ventil <= 0 || $pump <= 0) {
        IPS_LogMessage("IRR", "Zone oder Pumpe ungültig");
        return;
    }

    $active = intval($this->GetBuffer("ActiveZones"));

    // =========================================================
    //  ZONE EIN
    // =========================================================
    if ($state === true) {

        // Ventil öffnen
        KNX_WriteDPT1($ventil, true);

        // Anzahl aktiver Zonen erhöhen
        $active++;
        $this->SetBuffer("ActiveZones", strval($active));

        // Wenn es die ERSTE aktive Zone ist → Pumpe verzögert einschalten
        if ($active === 1) {
            $travelTimeSec = intval($zone["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
            $delayMs = $travelTimeSec * 1000;

            $this->SetBuffer("PumpOnPending", "1");
            $this->SetTimerInterval("PumpOnDelay", $delayMs);
        }

        return;
    }

    // =========================================================
    //  ZONE AUS
    // =========================================================
    KNX_WriteDPT1($ventil, false);

    // Anzahl aktiver Zonen reduzieren
    $active--;
    if ($active < 0) $active = 0;
    $this->SetBuffer("ActiveZones", strval($active));

    // Wenn keine Zone mehr aktiv → Pumpe aus
    if ($active === 0) {
        KNX_WriteDPT1($pump, false);
    }
}


    // ====================================================================================
    // TIMER
    // ====================================================================================
    public function PumpOnTimer()
    {
        if ($this->GetBuffer("PumpOnPending") === "1") {
            $pump = $this->ReadPropertyInteger("PumpID");
            if ($pump > 0) {
                KNX_WriteDPT1($pump, true);
                SetValue($this->GetIDForIdent("Pump"), true);
            }
        }

        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnDelay", 0);
    }

    // ====================================================================================
    // ZONENSTATUS AUSGEBEN
    // ====================================================================================
    public function GetZones()
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
