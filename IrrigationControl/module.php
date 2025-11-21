<?php

declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Properties aus form.json
        $this->RegisterPropertyInteger("MasterID", 0);
        $this->RegisterPropertyInteger("PumpID", 0);
        $this->RegisterPropertyInteger("GlobalTravelTime", 1); // Sekunden
        $this->RegisterPropertyString("ZoneList", "[]");

        // Timer für verzögertes Pumpeneinschalten
        $this->RegisterTimer("PumpOnDelay", 0, "IRR_PumpOnTimer(\$_IPS['TARGET']);");

        // Interner Status: wie viele Zonen sind aktiv?
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

    // ---------------------------------------------------------
    //  ----------- RequestAction für WebFront Schalter --------
    // ---------------------------------------------------------
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
            $this->SwitchMaster($Value);
            return;
        }
    }

    private function SwitchMaster(bool $state)
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


    // ---------------------------------------------------------
    //     -----------   Zonensteuerung   ----------------------
    // ---------------------------------------------------------
    public function SwitchZone(int $zoneIndex, bool $state)
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);

        if (!isset($zones[$zoneIndex])) {
            IPS_LogMessage("IRR", "Zone $zoneIndex existiert nicht");
            return;
        }

        $zone = $zones[$zoneIndex];
        $ventil = intval($zone["Ventil"]);

        // Verfahrzeit jetzt in Sekunden — Umwandlung in ms für Timer
        $travelSeconds = intval($zone["Verfahrzeit"] ?? $this->ReadPropertyInteger("GlobalTravelTime"));
        $travelMs = $travelSeconds * 1000;

        $pump = $this->ReadPropertyInteger("PumpID");

        if ($ventil <= 0 || $pump <= 0) {
            IPS_LogMessage("IRR", "Zone hat keine gültige Ventil- oder Pumpeninstanz");
            return;
        }

        $active = intval($this->GetBuffer("ActiveZones"));

        if ($state) {
            // =======================
            // ZONE EIN
            // =======================
            KNX_WriteDPT1($ventil, true);

            // aktive Zonen erhöhen
            $active++;
            $this->SetBuffer("ActiveZones", strval($active));

            // nur wenn vorher 0 Zonen aktiv waren → Pumpe verzögert einschalten
            if ($active === 1) {
                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnDelay", $travelMs);
            }
        } else {
            // =======================
            // ZONE AUS
            // =======================
            KNX_WriteDPT1($ventil, false);

            $active--;
            if ($active < 0) {
                $active = 0;
            }
            $this->SetBuffer("ActiveZones", strval($active));

            // wenn jetzt *keine einzige Zone mehr aktiv ist* → Pumpe aus
            if ($active === 0) {
                KNX_WriteDPT1($pump, false);
            }
        }
    }


    // ---------------------------------------------------------
    //     -----------   Timer: Pumpe EIN   --------------------
    // ---------------------------------------------------------
    public function PumpOnTimer()
    {
        $pending = intval($this->GetBuffer("PumpOnPending"));

        if ($pending === 1) {
            $pump = $this->ReadPropertyInteger("PumpID");
            if ($pump > 0) {
                KNX_WriteDPT1($pump, true);
            }
        }

        // Timer deaktivieren
        $this->SetTimerInterval("PumpOnDelay", 0);
        $this->SetBuffer("PumpOnPending", "0");
    }


    // Auto sequence triggers
    public function Sequence1Auto() {
        if ($this->ReadPropertyBoolean("Sequence1Enabled")) {
            $this->RunSequence(1);
        }
        $this->ScheduleNextAuto(1);
    }

    public function Sequence2Auto() {
        if ($this->ReadPropertyBoolean("Sequence2Enabled")) {
            $this->RunSequence(2);
        }
        $this->ScheduleNextAuto(2);
    }

    private function ScheduleNextAuto(int $seq) {
        $varIdent = "Sequence" . $seq . "StartVar";
        $vid = @IPS_GetObjectIDByIdent($varIdent, $this->InstanceID);
        if ($vid === false) return;
        $t = GetValue($vid);
        if (!preg_match('/^\d{2}:\d{2}$/', $t)) return;
        list($h,$m)=explode(":",$t);
        $now=time();
        $next=mktime($h,$m,0);
        if($next <= $now) $next+=86400;
        $timerName = "Sequence" . $seq . "AutoTimer";
        $this->SetTimerInterval($timerName, ($next-$now)*1000);
    }

}