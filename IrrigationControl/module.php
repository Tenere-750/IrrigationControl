<?php

declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    // Maximale Anzahl unterstützter Zonen
    private const MAX_ZONES = 10;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger("MasterID", 0);
        $this->RegisterPropertyInteger("PumpID", 0);
        $this->RegisterPropertyInteger("GlobalTravelTime", 7);
        $this->RegisterPropertyString("ZoneList", "[]");

        // GLOBALER Pumpen-Timer
        $this->RegisterTimer("PumpOnTimer", 0, "IRR_PumpOnTimer(\$_IPS['TARGET']);");

        // Buffer für aktive Zonen
        $this->SetBuffer("ActiveZones", "0");

        // Maximal mögliche Zonen-Timer vordefiniert registrieren
        for ($i = 0; $i < self::MAX_ZONES; $i++) {
            $this->RegisterTimer("ZoneDelayTimer_" . $i, 0, "IRR_ZoneDelayTimer(\$_IPS['TARGET'], $i);");
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterWebFrontVariables();

        // Timer-Intervalle für alle Zonen setzen (voreingestellt aus)
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones)) $zones = [];

        for ($i = 0; $i < self::MAX_ZONES; $i++) {
            if ($i < count($zones)) {
                // Hier könnte ein sinnvolles Startintervall gesetzt werden, z.B. 0 (Timer aus)
                $this->SetTimerInterval("ZoneDelayTimer_" . $i, 0);
            } else {
                // Falls Zone entfernt, Timer ebenfalls deaktivieren
                $this->SetTimerInterval("ZoneDelayTimer_" . $i, 0);
            }
        }
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
            if (!$this->VariableExistsByIdent($ident)) {
                $this->RegisterVariableBoolean($ident, $zone["Name"], "~Switch");
            }
            $this->EnableAction($ident);
        }
    }

    // ====================================================================================
    // REQUEST ACTION
    // ====================================================================================
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === "Master") {
            $this->Master($Value);

            $masterID = $this->ReadPropertyInteger("MasterID");
            $state = ($masterID > 0) ? (bool)KNX_RequestStatus($masterID) : false;
            SetValue($this->GetIDForIdent("Master"), $state);
            return;
        }

        if ($Ident === "Pump") {
            $this->Pump($Value);
            SetValue($this->GetIDForIdent("Pump"), $Value);
            return;
        }

        if (str_starts_with($Ident, "Zone")) {
            $index = intval(substr($Ident, 4));
            $ok = $this->SwitchZone($index, $Value);

            if ($ok) {
                SetValue($this->GetIDForIdent($Ident), $Value);
            } else {
                SetValue($this->GetIDForIdent($Ident), GetValue($this->GetIDForIdent($Ident)));
            }
            return;
        }
    }

    // ====================================================================================
    // MASTER
    // ====================================================================================
    public function Master(bool $state)
    {
        $masterID = $this->ReadPropertyInteger("MasterID");

        if ($masterID > 0) {
            KNX_WriteDPT1($masterID, $state);
        }

        if ($state === true) {
            $this->AllOff();
        }
    }

    // ====================================================================================
    // PUMPE
    // ====================================================================================
    public function Pump(bool $state)
    {
        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0) {
            KNX_WriteDPT1($pumpID, $state);
        }
    }

    // ====================================================================================
    // ALLE OFF
    // ====================================================================================
    public function AllOff()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones)) return;

        foreach ($zones as $i => $zone) {
            $ventil = intval($zone["Ventil"]);
            if ($ventil > 0) {
                KNX_WriteDPT1($ventil, false);
                SetValue($this->GetIDForIdent("Zone" . $i), false);
            }
            // Zonentimer stoppen
            $this->SetTimerInterval("ZoneDelayTimer_" . $i, 0);
        }

        // Pumpe aus
        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0) {
            KNX_WriteDPT1($pumpID, false);
        }

        $this->SetBuffer("ActiveZones", "0");

        // Pumpen-Timer stoppen
        $this->SetTimerInterval("PumpOnTimer", 0);
    }

    // ====================================================================================
    // ZONEN STEUERUNG
    // ====================================================================================
    public function SwitchZone(int $zoneIndex, bool $state): bool
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!isset($zones[$zoneIndex])) return false;

        $masterID = $this->ReadPropertyInteger("MasterID");
        if ($masterID > 0 && KNX_RequestStatus($masterID)) {
            IPS_LogMessage("IRR", "Master aktiv – Zone $zoneIndex blockiert");
            return false;
        }

        $zone = $zones[$zoneIndex];
        $ventil = intval($zone["Ventil"]);
        $pumpID = $this->ReadPropertyInteger("PumpID");

        $travelTime = intval($zone["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));

        $active = intval($this->GetBuffer("ActiveZones"));

        // ZONE EIN ------------------------------------------------------------
        if ($state) {
            KNX_WriteDPT1($ventil, true);

            $active++;
            $this->SetBuffer("ActiveZones", $active);

            // Wenn erste Zone aktiv → Timer starten
            if ($active === 1) {
                $this->SetBuffer("PumpStartSource", $zoneIndex);
                $this->SetTimerInterval("ZoneDelayTimer_" . $zoneIndex, $travelTime * 1000);
            }

            return true;
        }

        // ZONE AUS ------------------------------------------------------------
        KNX_WriteDPT1($ventil, false);

        // Zonentimer stoppen
        $this->SetTimerInterval("ZoneDelayTimer_" . $zoneIndex, 0);

        $active--;
        if ($active < 0) $active = 0;
        $this->SetBuffer("ActiveZones", $active);

        // Wenn keine Zonen mehr aktiv → Pumpe aus
        if ($active === 0 && $pumpID > 0) {
            KNX_WriteDPT1($pumpID, false);
        }

        return true;
    }

    // ====================================================================================
    // ZONEN-VERZÖGERUNGSTIMER (nicht blockierend)
    // ====================================================================================
    public function ZoneDelayTimer(int $zoneIndex)
    {
        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0) {
            KNX_WriteDPT1($pumpID, true);
            SetValue($this->GetIDForIdent("Pump"), true);
        }

        // Diesen Timer stoppen
        $this->SetTimerInterval("ZoneDelayTimer_" . $zoneIndex, 0);
    }

    // ====================================================================================
    // STATUS
    // ====================================================================================
    public function GetZones()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        $result = [];

        foreach ($zones as $i => $z) {
            $result[] = [
                "Name" => $z["Name"],
                "State" => GetValue($this->GetIDForIdent("Zone" . $i)),
                "Ventil" => $z["Ventil"]
            ];
        }

        return $result;
    }

    // ====================================================================================
    // HILFSFUNKTION
    // ====================================================================================
    private function VariableExistsByIdent(string $ident): bool
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return ($id !== false);
    }
}
