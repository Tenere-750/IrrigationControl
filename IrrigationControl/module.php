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

        // GLOBALER Pumpen-Timer (nur hier registrieren!)
        $this->RegisterTimer("PumpOnTimer", 0, "IRR_PumpOnTimer(\$_IPS['TARGET']);");

        // Buffer für aktive Zonen
        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // WebFront-Variablen (nur anlegen, nicht erneut Timer registrieren)
        $this->RegisterWebFrontVariables();

        // Timer deaktivieren (sicherer Startzustand)
        $this->SetTimerInterval("PumpOnTimer", 0);
        $this->SetBuffer("PumpOnPending", "0");

        // ActiveZones falls nicht gesetzt
        if ($this->GetBuffer("ActiveZones") === "") {
            $this->SetBuffer("ActiveZones", "0");
        }
    }

    // Registriere Variablen für WebFront (nur falls noch nicht vorhanden)
    private function RegisterWebFrontVariables(): void
    {
        // Master
        if (@IPS_GetObjectIDByIdent("Master", $this->InstanceID) === false) {
            $this->RegisterVariableBoolean("Master", "Master", "~Switch");
        }
        $this->EnableAction("Master");

        // Pump
        if (@IPS_GetObjectIDByIdent("Pump", $this->InstanceID) === false) {
            $this->RegisterVariableBoolean("Pump", "Pumpe", "~Switch");
        }
        $this->EnableAction("Pump");

        // Zones
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones)) return;
        foreach ($zones as $i => $zone) {
            $ident = "Zone" . $i;
            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
                $this->RegisterVariableBoolean($ident, ($zone["Name"] ?? "Zone " . ($i+1)), "~Switch");
            } else {
                // ggf. Beschriftung aktualisieren
                $vid = $this->GetIDForIdent($ident);
                if ($vid !== false && isset($zone["Name"])) {
                    IPS_SetName($vid, $zone["Name"]);
                }
            }
            $this->EnableAction($ident);
        }
    }

    // -----------------------------
    // RequestAction (WebFront)
    // -----------------------------
    public function RequestAction($Ident, $Value)
    {
        // MASTER
        if ($Ident === "Master") {
            $this->Master((bool)$Value);
            // Anzeige aktualisieren: möglichst aktuellen KNX-Wert lesen (Fallback auf gesetzten Wert)
            $masterID = $this->ReadPropertyInteger("MasterID");
            $state = $this->ReadMasterState($masterID);
            SetValue($this->GetIDForIdent("Master"), $state);
            return;
        }

        // PUMP
        if ($Ident === "Pump") {
            $this->Pump((bool)$Value);
            SetValue($this->GetIDForIdent("Pump"), (bool)$Value);
            return;
        }

        // ZONES
        if (str_starts_with($Ident, "Zone")) {
            $index = intval(substr($Ident, 4));
            $ok = $this->SwitchZone($index, (bool)$Value);
            // nur die Anzeige setzen, wenn Aktion OK war, sonst wieder aktuellen Status lesen
            if ($ok) {
                SetValue($this->GetIDForIdent($Ident), (bool)$Value);
            } else {
                // Anzeige zurücksetzen (aktueller Status)
                $cur = GetValue($this->GetIDForIdent($Ident));
                SetValue($this->GetIDForIdent($Ident), $cur);
            }
            return;
        }
    }

    // -----------------------------
    // MASTER: setzen & AllOff bei true
    // -----------------------------
    public function Master(bool $state): void
    {
        $masterID = $this->ReadPropertyInteger("MasterID");
        if ($masterID > 0 && IPS_InstanceExists($masterID)) {
            // Schreibbefehl an KNX-Instanz
            KNX_WriteDPT1($masterID, $state);
        } else {
            // falls keine KNX-Instanz konfiguriert: nur setzen der WebFront-Anzeige
            IPS_LogMessage("IrrigationControl", "MasterID nicht konfiguriert oder Instanz fehlt.");
        }

        if ($state === true) {
            // Master true → Not-Aus: alle aus
            $this->AllOff();
        }
    }

    // -----------------------------
    // PUMPE manuell
    // -----------------------------
    public function Pump(bool $state): void
    {
        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
            KNX_WriteDPT1($pumpID, $state);
        } else {
            IPS_LogMessage("IrrigationControl", "PumpID nicht konfiguriert oder Instanz fehlt.");
        }
    }

    // -----------------------------
    // ALLE AUS (Ventile zu, Pumpe aus, Timer reset)
    // -----------------------------
    public function AllOff(): void
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones)) $zones = [];

        foreach ($zones as $i => $z) {
            $ventil = intval($z["Ventil"] ?? 0);
            if ($ventil > 0 && IPS_InstanceExists($ventil)) {
                KNX_WriteDPT1($ventil, false);
            }
            // WebFront Anzeige
            if ($this->GetIDForIdent("Zone" . $i) !== false) {
                SetValue($this->GetIDForIdent("Zone" . $i), false);
            }
        }

        // pump off
        $pumpID = $this->ReadPropertyInteger("PumpID");
        if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
            KNX_WriteDPT1($pumpID, false);
        }
        if ($this->GetIDForIdent("Pump") !== false) {
            SetValue($this->GetIDForIdent("Pump"), false);
        }

        // buffers & timers
        $this->SetBuffer("ActiveZones", "0");
        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);
    }

    // -----------------------------
    // ZONE SCHALTEN (Hauptlogik)
    // - Öffnen: Ventil sofort verfahren, active++, wenn active==1 -> PumpOnTimer starten
    // - Schließen: Ventil schließen, active--, wenn active==0 -> Pumpe aus
    // Rückgabewert: bool success
    // -----------------------------
    public function SwitchZone(int $zoneIndex, bool $state): bool
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        if (!is_array($zones) || !isset($zones[$zoneIndex])) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: ungültiger ZoneIndex: $zoneIndex");
            return false;
        }

        // Master prüfen: wenn Master aktiv → blockieren
        $masterID = $this->ReadPropertyInteger("MasterID");
        $masterState = $this->ReadMasterState($masterID);
        if ($masterState === true) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: blockiert durch Master (Zone $zoneIndex).");
            return false;
        }

        $zone = $zones[$zoneIndex];
        $ventil = intval($zone["Ventil"] ?? 0);
        $pumpID = $this->ReadPropertyInteger("PumpID");

        if ($ventil <= 0 || !IPS_InstanceExists($ventil)) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: Ventil Instanz ungültig für Zone $zoneIndex.");
            return false;
        }
        if ($pumpID <= 0 || !IPS_InstanceExists($pumpID)) {
            IPS_LogMessage("IrrigationControl", "SwitchZone: Pumpen Instanz ungültig.");
            return false;
        }

        $active = intval($this->GetBuffer("ActiveZones"));

        // ZONE EIN
        if ($state === true) {
            // Ventil öffnen
            KNX_WriteDPT1($ventil, true);
            // Active erhöhen
            $active++;
            $this->SetBuffer("ActiveZones", strval($active));
            // Wenn erste aktive Zone -> Timer, um Pumpe nach Verfahrzeit einzuschalten
            if ($active === 1) {
                $travelSec = intval($zone["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
                if ($travelSec < 0) $travelSec = 0;
                $delayMs = $travelSec * 1000;
                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnTimer", $delayMs);
            }
            return true;
        }

        // ZONE AUS
        // Ventil schließen sofort
        KNX_WriteDPT1($ventil, false);
        // Active reduzieren
        $active--;
        if ($active < 0) $active = 0;
        $this->SetBuffer("ActiveZones", strval($active));
        // Wenn keine Zone mehr offen -> Pumpe aus
        if ($active === 0) {
            KNX_WriteDPT1($pumpID, false);
            if ($this->GetIDForIdent("Pump") !== false) {
                SetValue($this->GetIDForIdent("Pump"), false);
            }
            // Timer stoppen
            $this->SetBuffer("PumpOnPending", "0");
            $this->SetTimerInterval("PumpOnTimer", 0);
        }
        return true;
    }

    // -----------------------------
    // PumpOnTimer: wird nach Verfahrzeit ausgeführt -> Pumpe an
    // -----------------------------
    public function PumpOnTimer(): void
    {
        if ($this->GetBuffer("PumpOnPending") === "1") {
            $pumpID = $this->ReadPropertyInteger("PumpID");
            if ($pumpID > 0 && IPS_InstanceExists($pumpID)) {
                KNX_WriteDPT1($pumpID, true);
                if ($this->GetIDForIdent("Pump") !== false) {
                    SetValue($this->GetIDForIdent("Pump"), true);
                }
            }
        }
        // Reset
        $this->SetBuffer("PumpOnPending", "0");
        $this->SetTimerInterval("PumpOnTimer", 0);
    }

    // -----------------------------
    // ZONENSTATUS AUSGEBEN
    // -----------------------------
    public function GetZones(): array
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        $res = [];
        if (!is_array($zones)) return $res;
        foreach ($zones as $i => $z) {
            $res[] = [
                "Index" => $i,
                "Name"  => ($z["Name"] ?? "Zone " . ($i+1)),
                "State" => ($this->GetIDForIdent("Zone".$i) !== false) ? GetValue($this->GetIDForIdent("Zone".$i)) : false,
                "Ventil" => ($z["Ventil"] ?? 0)
            ];
        }
        return $res;
    }

    // -----------------------------
    // Hilfsfunktion: Master-Zustand lesen
    // - versucht KNX_RequestStatus (wenn verfügbar), falls nicht -> GetValue auf MasterID
    // -----------------------------
    private function ReadMasterState(int $masterID): bool
    {
        if ($masterID <= 0) return false;
        // Wenn KNX_RequestStatus vorhanden, rufe an (kann asynchron sein)
        if (function_exists('KNX_RequestStatus')) {
            try {
                $res = @KNX_RequestStatus($masterID);
                // KNX_RequestStatus kann void oder bool zurückgeben, wir interpretieren best-effort
                if (is_bool($res)) {
                    return $res;
                }
            } catch (Throwable $e) {
                // ignore and fallback
            }
        }
        // Fallback: falls das Objekt eine Variable ist, lese deren Wert
        if (@IPS_VariableExists($masterID)) {
            return (bool)GetValue($masterID);
        }
        // ansonsten false
        return false;
    }
}
