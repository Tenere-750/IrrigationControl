<?php
declare(strict_types=1);

class IrrigationControl extends IPSModule
{
    private const ZONE_COUNT_MAX = 20; // safety upper bound (UI limits it to 7 rows but be safe)

    public function Create()
    {
        parent::Create();

        // properties
        $this->RegisterPropertyInteger("MasterID", 0);
        $this->RegisterPropertyInteger("PumpID", 0);
        $this->RegisterPropertyInteger("GlobalTravelTime", 7); // seconds
        $this->RegisterPropertyInteger("MaxParallelZones", 2);
        $this->RegisterPropertyString("ZoneList", "[]");

        // sequences
        $this->RegisterPropertyBoolean("Sequence1Enabled", false);
        $this->RegisterPropertyString("Sequence1Start", "06:00");
        $this->RegisterPropertyString("Sequence1Order", "");

        $this->RegisterPropertyBoolean("Sequence2Enabled", false);
        $this->RegisterPropertyString("Sequence2Start", "20:00");
        $this->RegisterPropertyString("Sequence2Order", "");

        // timers (must be registered in Create)
        $this->RegisterTimer("SequenceTimer1", 0, 'IRR_RunSequence(' . $this->InstanceID . ',1);');
        $this->RegisterTimer("SequenceTimer2", 0, 'IRR_RunSequence(' . $this->InstanceID . ',2);');
        $this->RegisterTimer("PumpOnTimer", 0, 'IRR_PumpOnTimer(' . $this->InstanceID . ');');

        // per-zone timers: ZoneRunTimer_<i> (stop zone after runtime) and CloseDelayTimer_<i> (close previous after travel)
        for ($i=0; $i<7; $i++) {
            $this->RegisterTimer('ZoneRunTimer_' . $i, 0, 'IRR_ZoneStopTimer(' . $this->InstanceID . ',' . $i . ');');
            $this->RegisterTimer('CloseDelayTimer_' . $i, 0, 'IRR_CloseDelayTimer(' . $this->InstanceID . ',' . $i . ');');
        }

        // buffers
        $this->SetBuffer('ActiveZones', '0'); // integer as string
        $this->SetBuffer('SequenceState', json_encode(['running'=>false,'seq'=>0,'queue'=>[]]));
        $this->SetBuffer('PumpOnPending', '0');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // create WebFront variables for manual control
        $this->RegisterWebFrontVariables();

        // setup sequence timers according to configured start times
        $this->SetupSequenceTimer(1, $this->ReadPropertyString('Sequence1Start'), $this->ReadPropertyBoolean('Sequence1Enabled'));
        $this->SetupSequenceTimer(2, $this->ReadPropertyString('Sequence2Start'), $this->ReadPropertyBoolean('Sequence2Enabled'));
    }

    // Register WebFront variables (Master, Pump, ZoneX)
    private function RegisterWebFrontVariables(): void
    {
        if (@IPS_GetObjectIDByIdent('Master', $this->InstanceID) === false) {
            $this->RegisterVariableBoolean('Master', 'Master', '~Switch');
            $this->EnableAction('Master');
        }
        if (@IPS_GetObjectIDByIdent('Pump', $this->InstanceID) === false) {
            $this->RegisterVariableBoolean('Pump', 'Pumpe', '~Switch');
            $this->EnableAction('Pump');
        }

        $zones = json_decode($this->ReadPropertyString('ZoneList'), true);
        if (!is_array($zones)) $zones = [];

        for ($i=0; $i<count($zones); $i++) {
            $ident = 'Zone' . $i;
            if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
                $this->RegisterVariableBoolean($ident, $zones[$i]['Name'] ?? 'Zone ' . ($i+1), '~Switch');
            } else {
                // update name if changed
                $vid = $this->GetIDForIdent($ident);
                if ($vid !== false && isset($zones[$i]['Name'])) {
                    IPS_SetName($vid, $zones[$i]['Name']);
                }
            }
            $this->EnableAction($ident);
        }
    }

    // ========================================================================
    // RequestAction - WebFront control
    // ========================================================================
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Master') {
            $this->IRR_Master((bool)$Value);
            // update displayed state by reading KNX instance (best-effort)
            $masterID = $this->ReadPropertyInteger('MasterID');
            $state = $this->ReadMasterState($masterID);
            SetValue($this->GetIDForIdent('Master'), $state);
            return;
        }

        if ($Ident === 'Pump') {
            $this->IRR_Pump((bool)$Value);
            SetValue($this->GetIDForIdent('Pump'), (bool)$Value);
            return;
        }

        if (str_starts_with($Ident, 'Zone')) {
            $index = intval(substr($Ident, 4));
            $ok = $this->IRR_SwitchZone($index, (bool)$Value);
            // only set WF variable if action succeeded
            if ($ok) SetValue($this->GetIDForIdent($Ident), (bool)$Value);
            return;
        }
    }

    // ========================================================================
    // Sequenz Timer Setup
    // ========================================================================
    private function SetupSequenceTimer(int $seq, string $timeStr, bool $enabled): void
    {
        $timerIdent = 'SequenceTimer' . $seq;
        if (!$enabled) {
            $this->SetTimerInterval($timerIdent, 0);
            return;
        }
        $next = $this->CalcNextEventTimestamp($timeStr);
        $now = time();
        $intervalSec = max(1, $next - $now);
        $this->SetTimerInterval($timerIdent, $intervalSec * 1000);
    }

    // ========================================================================
    // RunSequence - timer callback or manual call
    // seqNumber: 1 or 2
    // ========================================================================
    public function RunSequence(int $seqNumber)
    {
        // only run if Master is false
        $master = $this->ReadMasterState($this->ReadPropertyInteger('MasterID'));
        if ($master === true) {
            IPS_LogMessage('IrrigationControl','RunSequence ' . $seqNumber . ' blocked by Master.');
            // reschedule next
            $this->SetupSequenceTimer($seqNumber, $this->ReadPropertyString('Sequence' . $seqNumber . 'Start'), true);
            return;
        }

        $enabled = $this->ReadPropertyBoolean('Sequence' . $seqNumber . 'Enabled');
        if (!$enabled) {
            $this->SetupSequenceTimer($seqNumber, $this->ReadPropertyString('Sequence' . $seqNumber . 'Start'), false);
            return;
        }

        // Build sequence queue
        $orderStr = trim($this->ReadPropertyString('Sequence' . $seqNumber . 'Order'));
        if ($orderStr === '') {
            IPS_LogMessage('IrrigationControl','RunSequence ' . $seqNumber . ': empty order');
            $this->SetupSequenceTimer($seqNumber, $this->ReadPropertyString('Sequence' . $seqNumber . 'Start'), true);
            return;
        }

        $indices = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $orderStr))), function($v){ return $v >= 0; }));

        $zones = json_decode($this->ReadPropertyString('ZoneList'), true);
        if (!is_array($zones)) $zones = [];

        // filter indices to valid, enabled zones and with valid ventil instance
        $queue = [];
        foreach ($indices as $idx) {
            if (!isset($zones[$idx])) continue;
            $z = $zones[$idx];
            if (!($z['Enabled'] ?? true)) continue;
            $vent = intval($z['Ventil'] ?? 0);
            if ($vent <= 0 || !IPS_InstanceExists($vent)) continue;
            $queue[] = $idx;
        }

        if (count($queue) === 0) {
            IPS_LogMessage('IrrigationControl','RunSequence ' . $seqNumber . ': no valid zones');
            $this->SetupSequenceTimer($seqNumber, $this->ReadPropertyString('Sequence' . $seqNumber . 'Start'), true);
            return;
        }

        IPS_LogMessage('IrrigationControl','RunSequence ' . $seqNumber . ' starting: queue=' . implode(',', $queue));

        // store sequence state in buffer
        $state = ['running'=>true,'seq'=>$seqNumber,'queue'=>$queue,'pointer'=>0];
        $this->SetBuffer('SequenceState', json_encode($state));

        // start as many zones as allowed by MaxParallelZones
        $this->SequenceStartNext();
        // reschedule next day's run
        $this->SetupSequenceTimer($seqNumber, $this->ReadPropertyString('Sequence' . $seqNumber . 'Start'), true);
    }

    // ========================================================================
    // SequenceStartNext: open next zones up to MaxParallelZones
    // ========================================================================
    private function SequenceStartNext(): void
    {
        $state = json_decode($this->GetBuffer('SequenceState'), true);
        if (!is_array($state) || !$state['running']) return;

        $queue = $state['queue'];
        $pointer = intval($state['pointer']);
        $maxParallel = max(1, intval($this->ReadPropertyInteger('MaxParallelZones')));
        $active = intval($this->GetBuffer('ActiveZones'));

        // open while we can
        while ($pointer < count($queue) && $active < $maxParallel) {
            $zoneIndex = $queue[$pointer];
            $ok = $this->OpenZoneForSequence($zoneIndex);
            if (!$ok) {
                IPS_LogMessage('IrrigationControl','SequenceStartNext: failed to open zone ' . $zoneIndex);
            } else {
                $active++;
                $this->SetBuffer('ActiveZones', strval($active));
            }
            // if there was a previously opened zone and it must be closed after travel time,
            // schedule CloseDelayTimer for the previous zone (to close after travel)
            if ($pointer-1 >= 0) {
                $prev = $queue[$pointer-1];
                $travel = $this->GetZoneTravel($prev);
                $this->SetTimerInterval('CloseDelayTimer_' . $prev, intval($travel * 1000));
            }
            $pointer++;
        }

        $state['pointer'] = $pointer;
        // if pointer reached end and no more active zones -> sequence finished; else store state
        $state['running'] = ($pointer < count($queue) || $this->GetBuffer('ActiveZones') !== '0');
        $this->SetBuffer('SequenceState', json_encode($state));
    }

    // ========================================================================
    // Open a zone as part of a sequence:
    // - open valve immediately
    // - start ZoneRunTimer_<i> for the runtime
    // - if this is the first active zone -> schedule PumpOnTimer after travel
    // ========================================================================
    private function OpenZoneForSequence(int $zoneIndex): bool
    {
        $zones = json_decode($this->ReadPropertyString('ZoneList'), true);
        if (!isset($zones[$zoneIndex])) return false;
        $zone = $zones[$zoneIndex];
        $vent = intval($zone['Ventil'] ?? 0);
        if ($vent <= 0 || !IPS_InstanceExists($vent)) return false;

        // open valve
        KNX_WriteDPT1($vent, true);
        IPS_LogMessage('IrrigationControl','OpenZoneForSequence: opened valve ' . $vent . ' (zone ' . $zoneIndex . ')');

        // determine runtime minutes for current sequence (SequenceState contains seq)
        $state = json_decode($this->GetBuffer('SequenceState'), true);
        $seq = intval($state['seq'] ?? 1);
        $runtimeMin = intval($zone['RuntimeSeq' . $seq] ?? 0);
        if ($runtimeMin <= 0) $runtimeMin = 1; // default 1 minute
        $runtimeMs = $runtimeMin * 60 * 1000;

        // start zone run timer to stop this zone after runtime
        $this->SetTimerInterval('ZoneRunTimer_' . $zoneIndex, $runtimeMs);

        // if this is the first active zone -> schedule pump on after travel
        $active = intval($this->GetBuffer('ActiveZones'));
        if ($active === 0) {
            $travel = $this->GetZoneTravel($zoneIndex);
            $this->SetBuffer('PumpOnPending', '1');
            $this->SetTimerInterval('PumpOnTimer', intval($travel * 1000));
            IPS_LogMessage('IrrigationControl','PumpOnTimer scheduled after ' . $travel . 's');
        }

        return true;
    }

    // ========================================================================
    // Close Delay Timer callback: closes previous zone after travel time
    // ========================================================================
    public function CloseDelayTimer(int $zoneIndex)
    {
        // close this zone (called after next valve opened and travel delay)
        $zones = json_decode($this->ReadPropertyString('ZoneList'), true);
        if (!isset($zones[$zoneIndex])) return;
        $vent = intval($zones[$zoneIndex]['Ventil'] ?? 0);
        if ($vent > 0 && IPS_InstanceExists($vent)) {
            KNX_WriteDPT1($vent, false);
            IPS_LogMessage('IrrigationControl','CloseDelayTimer: closed valve of zone ' . $zoneIndex);
            // decrement active
            $active = intval($this->GetBuffer('ActiveZones'));
            $active = max(0, $active - 1);
            $this->SetBuffer('ActiveZones', strval($active));
            // if no active zones -> stop pump
            if ($active === 0) {
                $pump = $this->ReadPropertyInteger('PumpID');
                if ($pump > 0 && IPS_InstanceExists($pump)) {
                    KNX_WriteDPT1($pump, false);
                    if ($this->GetIDForIdent('Pump') !== false) SetValue($this->GetIDForIdent('Pump'), false);
                }
                // sequence may be finished; ensure SequenceStartNext updates state
                $this->SequenceStartNext();
            } else {
                // try to start more zones if there is queue left
                $this->SequenceStartNext();
            }
        }
        // stop timer
        $this->SetTimerInterval('CloseDelayTimer_' . $zoneIndex, 0);
    }

    // ========================================================================
    // ZoneRunTimer callback: triggered when a zone's runtime is finished -> close zone
    // ========================================================================
    public function ZoneStopTimer(int $zoneIndex)
    {
        $zones = json_decode($this->ReadPropertyString('ZoneList'), true);
        if (!isset($zones[$zoneIndex])) return;
        $vent = intval($zones[$zoneIndex]['Ventil'] ?? 0);
        if ($vent > 0 && IPS_InstanceExists($vent)) {
            KNX_WriteDPT1($vent, false);
            IPS_LogMessage('IrrigationControl','ZoneStopTimer: runtime ended, closed zone ' . $zoneIndex);
            // decrement active
            $active = intval($this->GetBuffer('ActiveZones'));
            $active = max(0, $active - 1);
            $this->SetBuffer('ActiveZones', strval($active));
            // if no active left -> stop pump
            if ($active === 0) {
                $pump = $this->ReadPropertyInteger('PumpID');
                if ($pump > 0 && IPS_InstanceExists($pump)) {
                    KNX_WriteDPT1($pump, false);
                    if ($this->GetIDForIdent('Pump') !== false) SetValue($this->GetIDForIdent('Pump'), false);
                }
            }
            // sequence continue opening next queued zone(s) if any
            $this->SequenceStartNext();
        }
        // stop this timer
        $this->SetTimerInterval('ZoneRunTimer_' . $zoneIndex, 0);
    }

    // ========================================================================
    // PumpOnTimer: after travel, turn pump on
    // ========================================================================
    public function PumpOnTimer()
    {
        if ($this->GetBuffer('PumpOnPending') !== '1') {
            $this->SetTimerInterval('PumpOnTimer', 0);
            return;
        }
        $pump = $this->ReadPropertyInteger('PumpID');
        if ($pump > 0 && IPS_InstanceExists($pump)) {
            KNX_WriteDPT1($pump, true);
            if ($this->GetIDForIdent('Pump') !== false) SetValue($this->GetIDForIdent('Pump'), true);
            IPS_LogMessage('IrrigationControl','PumpOnTimer: pump switched on');
        }
        $this->SetBuffer('PumpOnPending', '0');
        $this->SetTimerInterval('PumpOnTimer', 0);
    }

    // ========================================================================
    // Helper: get travel time (zone-specific or global)
    // ========================================================================
    private function GetZoneTravel(int $zoneIndex): int
    {
        $zones = json_decode($this->ReadPropertyString('ZoneList'), true);
        if (!isset($zones[$zoneIndex])) return intval($this->ReadPropertyInteger('GlobalTravelTime'));
        $v = intval($zones[$zoneIndex]['Verfahrzeit'] ?? 0);
        if ($v <= 0) return intval($this->ReadPropertyInteger('GlobalTravelTime'));
        return $v;
    }

    // ========================================================================
    // Helper: calculate next event timestamp given "HH:MM"
    // ========================================================================
    private function CalcNextEventTimestamp(string $timeStr): int
    {
        $parts = explode(':', $timeStr);
        $hour = intval($parts[0] ?? 0);
        $minute = intval($parts[1] ?? 0);
        $now = time();
        $candidate = mktime($hour, $minute, 0);
        if ($candidate <= $now) $candidate += 86400;
        return $candidate;
    }

    // ========================================================================
    // Read master real state (try KNX children variables or GetValue)
    // ========================================================================
    private function ReadMasterState(int $masterID): bool
    {
        if ($masterID <= 0 || !IPS_InstanceExists($masterID)) return false;
        $children = @IPS_GetChildrenIDs($masterID);
        if (is_array($children)) {
            foreach ($children as $cid) {
                if (IPS_VariableExists($cid)) {
                    return (bool)GetValue($cid);
                }
            }
        }
        // fallback
        if (@IPS_VariableExists($masterID)) {
            return (bool)GetValue($masterID);
        }
        return false;
    }

    // ========================================================================
    // Public wrappers (used in form/action buttons)
    // ========================================================================
    public function RunSequence(int $seq) { $this->RunSequence($seq); } // not used directly, kept for compatibility
    public function IRR_RunSequence(int $id, int $seq) { $this->RunSequence($seq); } // timer-callback compatibility
    public function IRR_PumpOnTimer(int $id) { $this->PumpOnTimer(); } // timer-callback
    public function IRR_ZoneStopTimer(int $id, int $zoneIndex) { $this->ZoneStopTimer($zoneIndex); }
    public function IRR_CloseDelayTimer(int $id, int $zoneIndex) { $this->CloseDelayTimer($zoneIndex); }
    public function IRR_SwitchZone(int $id, int $zoneIndex, bool $state) { $this->IRR_SwitchZone_Internal($zoneIndex, $state); }
    // Note: To avoid double prefix confusion the module methods are internal; wrapper names above match the IRR_... callbacks expected by the system.

    // internal implementation used by wrapper
    public function IRR_SwitchZone_Internal(int $zoneIndex, bool $state): bool
    {
        return $this->IRR_SwitchZone($zoneIndex, $state);
    }

    // direct public method used internally and by RequestAction
    public function IRR_SwitchZone(int $zoneIndex, bool $state): bool
    {
        // keep compatibility with RequestAction (when called as module method)
        return $this->IRR_SwitchZone_Internal($zoneIndex, $state);
    }
}
