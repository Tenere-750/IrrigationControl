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

    // ----------------------------------------------------------
    //  ----------- RequestAction für WebFront Schalter ---------
    // ----------------------------------------------------------
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
            KNX_WriteDPT1($ventil, true);

            $active++;
            $this->SetBuffer("ActiveZones", strval($active));

            if ($active === 1) {
                $this->SetBuffer("PumpOnPending", "1");
                $this->SetTimerInterval("PumpOnDelay", $travelMs);
            }
        } else {
            KNX_WriteDPT1($ventil, false);

            $active--;
            if ($active < 0) $active = 0;
            $this->SetBuffer("ActiveZones", strval($active));

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

        $this->SetTimerInterval("PumpOnDelay", 0);
        $this->SetBuffer("PumpOnPending", "0");
    }



    // ========================================================================
    // ========================================================================
    //  🔽🔽🔽  HIER beginnen die NEUEN IRR_* FUNKTIONEN (nur Ergänzung) 🔽🔽🔽
    // ========================================================================
    // ========================================================================


    // IRR_SwitchZone — öffentliche API
    public function IRR_SwitchZone(int $zoneIndex, bool $state)
    {
        $this->SwitchZone($zoneIndex, $state);
    }

    // IRR_Pump — öffentliche API
    public function IRR_Pump(bool $state)
    {
        $this->ManualPump($state);
    }

    // IRR_Master — Master EIN/AUS inklusive Abschaltlogik
    public function IRR_Master(bool $state)
    {
        // Master setzen
        $this->SwitchMaster($state);

        if ($state === true) {
            // Wenn Master aktiviert → alles ausschalten
            $this->IRR_AllOff();
        }
    }

    // IRR_AllOff — Pumpe aus + Ventile schließen
    public function IRR_AllOff()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        $pump = $this->ReadPropertyInteger("PumpID");

        foreach ($zones as $z) {
            if (isset($z["Ventil"]) && $z["Ventil"] > 0) {
                KNX_WriteDPT1($z["Ventil"], false);
            }
        }

        if ($pump > 0) {
            KNX_WriteDPT1($pump, false);
        }

        $this->SetBuffer("ActiveZones", "0");
    }

    // IRR_GetZones — Statusabfrage
    public function IRR_GetZones()
    {
        $zones = json_decode($this->ReadPropertyString("ZoneList"), true);
        $pump = $this->ReadPropertyInteger("PumpID");

        $result = [];

        foreach ($zones as $i => $z) {
            $ventil = intval($z["Ventil"]);
            $status = null;

            if ($ventil > 0 && IPS_VariableExists($ventil)) {
                $status = GetValue($ventil);
            }

            $result[] = [
                "index" => $i,
                "name"  => $z["Name"] ?? ("Zone " . $i),
                "ventil" => $ventil,
                "isOn" => $status
            ];
        }

        $pumpStatus = null;
        if ($pump > 0 && IPS_VariableExists($pump)) {
            $pumpStatus = GetValue($pump);
        }

        return [
            "zones" => $result,
            "pump"  => $pumpStatus
        ];
    }

}
