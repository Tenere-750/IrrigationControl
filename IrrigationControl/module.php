<?php
declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // Global properties
        $this->RegisterPropertyInteger('PumpVariableID', 0);
        $this->RegisterPropertyInteger('ValveActuationTime', 7); // seconds

        // Master switch default property (visible in form)
        $this->RegisterPropertyBoolean('MasterSwitchDefault', true);

        // Per-zone configuration
        for ($i=1;$i<=7;$i++) {
            $this->RegisterPropertyString("ZoneName{$i}", "Zone {$i}");
            $this->RegisterPropertyInteger("ValveVar{$i}", 0);
            // visible state variable
            $this->RegisterVariableBoolean("ValveState{$i}", "Kreis {$i}", "~Switch", 100 + $i);
            $this->EnableAction("ValveState{$i}");
        }

        // Pump variable (visible)
        $this->RegisterVariableBoolean('PumpState', 'Pumpe', '~Switch', 20);
        $this->EnableAction('PumpState');

        // Master switch (visible control)
        $this->RegisterVariableBoolean('MasterSwitch', 'Master Switch', '', 30);
        $this->EnableAction('MasterSwitch');

        // Status vars
        $this->RegisterVariableString('Status', 'Status', '', 40);

        // Timers (none initial)
        $this->RegisterAttributeInteger('PumpRunningSince', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // ensure MasterSwitch default value when instance created
        if ($this->ReadPropertyBoolean('MasterSwitchDefault') && !$this->GetValue('MasterSwitch')) {
            $this->SetValue('MasterSwitch', true);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'PumpState':
                $this->HandlePumpAction((bool)$Value);
                break;
            case 'MasterSwitch':
                $this->HandleMasterSwitch((bool)$Value);
                break;
            default:
                // Valve states: ValveState1..7
                for ($i=1;$i<=7;$i++) {
                    if ($Ident === "ValveState{$i}") {
                        $this->HandleValveAction($i, (bool)$Value);
                        return;
                    }
                }
                break;
        }
    }

    // Public control APIs (can be called via IPS_RunScript or RPC)
    public function IRR_OpenZone(int $zone)
    {
        $this->OpenZoneInternal($zone);
    }

    public function IRR_CloseZone(int $zone)
    {
        $this->CloseZoneInternal($zone);
    }

    public function IRR_StopAll()
    {
        $this->MasterEmergencyStop();
    }

    public function IRR_SetValveTime(int $seconds)
    {
        $this->WritePropertyInteger('ValveActuationTime', $seconds);
    }

    public function IRR_SetPumpID(int $varid)
    {
        $this->WritePropertyInteger('PumpVariableID', $varid);
    }

    public function IRR_SetZoneValveID(int $zone, int $varid)
    {
        if ($zone < 1 || $zone > 7) return;
        $this->WritePropertyInteger("ValveVar{$zone}", $varid);
    }

    // Handle pump action via UI or API
    private function HandlePumpAction(bool $value)
    {
        if (!$this->IsMasterEnabled()) {
            // If master is OFF, pump cannot be started manually
            if ($value) {
                $this->Log('Pump start blocked by MasterSwitch OFF');
                $this->SetValue('Status', 'Master off - pump start blocked');
                // revert UI to false
                $this->SetValue('PumpState', false);
                return;
            }
        }
        $this->SetPump($value);
    }

    private function HandleValveAction(int $zone, bool $value)
    {
        if (!$this->IsMasterEnabled()) {
            // manual valve actions blocked when master is off
            $this->Log("Manual valve action for zone {$zone} blocked (Master OFF)");
            $this->SetValue('Status', 'Master off - valve actions blocked');
            // revert UI
            $this->SetValue("ValveState{$zone}", false);
            return;
        }
        if ($value) $this->OpenZoneInternal($zone);
        else $this->CloseZoneInternal($zone);
    }

    // Master switch handler
    private function HandleMasterSwitch(bool $value)
    {
        $this->SetValue('MasterSwitch', $value);
        if (!$value) {
            // Master turned OFF: pump immediately off, then close all valves and block controls
            $this->Log('MasterSwitch turned OFF - executing emergency stop');
            $this->SetPump(false); // immediate pump off
            // close all valves
            for ($i=1;$i<=7;$i++) {
                $this->SetValveHardware($i, false);
                $this->SetValue("ValveState{$i}", false);
            }
            $this->SetValue('Status', 'Master OFF - all outputs disabled');
        } else {
            // Master turned ON: allow manual control, but do not auto-start pump/valves
            $this->Log('MasterSwitch turned ON - manual control re-enabled');
            $this->SetValue('Status', 'Master ON - manual control enabled');
        }
    }

    // Check master enabled state
    private function IsMasterEnabled(): bool
    {
        return (bool)$this->GetValue('MasterSwitch');
    }

    // Core: open a zone (internal logic)
    private function OpenZoneInternal(int $zone)
    {
        if ($zone < 1 || $zone > 7) return;
        if (!$this->IsMasterEnabled()) {
            $this->Log("OpenZone {$zone} blocked - Master OFF");
            return;
        }
        // ensure valve var configured
        $var = (int)$this->ReadPropertyInteger("ValveVar{$zone}");
        if ($var <= 0) {
            $this->Log("OpenZone {$zone} - Valve variable not configured");
            $this->SetValue('Status', "Zone {$zone}: valve var not configured");
            return;
        }

        // Open valve first
        $this->SetValveHardware($zone, true);
        $this->SetValue("ValveState{$zone}", true);
        $this->Log("Zone {$zone} valve opened (physical var {$var})");

        // Wait valve actuation time (seconds)
        $act = max(1, (int)$this->ReadPropertyInteger('ValveActuationTime'));
        IPS_Sleep($act * 1000);

        // Start pump if not running
        if (!$this->GetValue('PumpState')) {
            $this->SetPump(true);
        }
        $this->SetValue('Status', "Zone {$zone} running");
    }

    // Close zone internal: stop valve and possibly stop pump if no other zone active
    private function CloseZoneInternal(int $zone)
    {
        if ($zone < 1 || $zone > 7) return;
        // Turn pump off first (per spec)
        $this->SetPump(false);
        $this->Log("Zone {$zone} requested close: pump off initiated");
        // Wait actuation time before closing valve
        $act = max(1, (int)$this->ReadPropertyInteger('ValveActuationTime'));
        IPS_Sleep($act * 1000);
        // Close the valve physical
        $this->SetValveHardware($zone, false);
        $this->SetValue("ValveState{$zone}", false);
        $this->Log("Zone {$zone} valve closed");
        // If any other valve state true, keep pump on; otherwise pump remains off (already set)
        $any = false;
        for ($i=1;$i<=7;$i++) {
            if ($this->GetValue("ValveState{$i}")) { $any = true; break; }
        }
        if (!$any) {
            $this->SetPump(false);
            $this->SetValue('Status', "Zone {$zone} stopped; pump off");
        } else {
            $this->SetValue('Status', "Zone {$zone} stopped; other zones active");
        }
    }

    // Set pump state physically (write to configured variable ID)
    private function SetPump(bool $state)
    {
        // If master disabled, force pump off
        if (!$this->IsMasterEnabled() && $state) {
            $this->Log('SetPump blocked by Master OFF');
            $this->SetValue('PumpState', false);
            return;
        }
        $pid = (int)$this->ReadPropertyInteger('PumpVariableID');
        if ($pid > 0 && @IPS_VariableExists($pid)) {
            SetValue($pid, $state);
            $this->Log('Pump physical var ' . $pid . ' set to ' . ($state ? 'ON' : 'OFF'));
        } else {
            $this->Log('Pump variable not configured or not exists');
        }
        $this->SetValue('PumpState', $state);
        if ($state) $this->WriteAttributeInteger('PumpRunningSince', time());
        else $this->WriteAttributeInteger('PumpRunningSince', 0);
    }

    // Low-level: write valve hardware variable
    private function SetValveHardware(int $zone, bool $state)
    {
        $var = (int)$this->ReadPropertyInteger("ValveVar{$zone}");
        if ($var > 0 && @IPS_VariableExists($var)) {
            SetValue($var, $state);
            $this->Log("Valve hardware: var {$var} set to " . ($state ? 'ON' : 'OFF'));
        } else {
            $this->Log("Valve hardware var for zone {$zone} not configured");
        }
    }

    // Emergency stop: stops pump and closes all valves immediately
    private function MasterEmergencyStop()
    {
        $this->SetPump(false);
        for ($i=1;$i<=7;$i++) {
            $this->SetValveHardware($i, false);
            $this->SetValue("ValveState{$i}", false);
        }
        $this->SetValue('Status', 'Emergency stop executed');
    }
}
?>