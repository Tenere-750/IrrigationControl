<?php

declare(strict_types=1);

class Bewaesserungssteuerung extends IPSModule
{
    private const SLOT_MORNING = 'morning';
    private const SLOT_EVENING = 'evening';
    private const ZONE_COUNT = 7;
    private const LAWN_RIGHT_ZONE = 3;
    private const LAWN_LEFT_ZONE = 4;
    private const DAYS = [
        1 => 'Mo',
        2 => 'Di',
        3 => 'Mi',
        4 => 'Do',
        5 => 'Fr',
        6 => 'Sa',
        7 => 'So'
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('MasterEnabled', true);
        $this->RegisterPropertyBoolean('AutomaticEnabled', true);
        $this->RegisterPropertyInteger('PumpID', 0);
        $this->RegisterPropertyInteger('DefaultValveTravelTime', 7);
        $this->RegisterPropertyInteger('ValveOverlapTime', 10);
        $this->RegisterPropertyString('MorningStartTime', '06:00');
        $this->RegisterPropertyString('EveningStartTime', '20:00');
        $this->RegisterPropertyInteger('MorningDuration', 10);
        $this->RegisterPropertyInteger('EveningDuration', 10);
        $this->RegisterPropertyInteger('SoilMoistureThreshold', 80);
        $this->RegisterPropertyString('Zones', json_encode($this->DefaultZones()));
        $this->RegisterPropertyString('DayPlan', json_encode($this->DefaultDayPlan()));

        $this->RegisterAttributeString('LastRunKey', '');
        $this->RegisterAttributeString('LastDailyReset', '');
        $this->RegisterAttributeBoolean('VariablesInitialized', false);
        $this->RegisterAttributeString('ManualActiveStarts', '{}');
        $this->RegisterAttributeString('ActiveZoneIndexes', '[]');

        $this->RegisterTimer('ScheduleTimer', 60000, 'BWS_CheckSchedules($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->RegisterWebFrontVariables();
        $this->SyncInitialPlannerValues();
        $this->SetTimerInterval('ScheduleTimer', 60000);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'MasterSwitch') {
            SetValueBoolean($this->GetIDForIdent($Ident), (bool) $Value);
            if (!$Value) {
                $this->StopAll();
            }
            return;
        }

        if ($Ident === 'AutomaticMode') {
            SetValueBoolean($this->GetIDForIdent($Ident), (bool) $Value);
            return;
        }

        if (in_array($Ident, ['MorningStartTime', 'EveningStartTime'], true)) {
            $time = $this->NormalizeTime((string) $Value);
            SetValueString($this->GetIDForIdent($Ident), $time);
            return;
        }

        if (in_array($Ident, ['LawnMorningDuration', 'LawnEveningDuration'], true)) {
            SetValueInteger($this->GetIDForIdent($Ident), max(1, (int) $Value));
            return;
        }

        if ($Ident === 'ManualLawn') {
            if ((bool) $Value) {
                $this->StartManualZone(self::LAWN_RIGHT_ZONE);
            } else {
                $this->StopLawn();
            }
            return;
        }

        if (preg_match('/^ManualZone([1-7])$/', $Ident, $matches)) {
            $zoneIndex = (int) $matches[1];
            if ((bool) $Value) {
                $this->StartManualZone($zoneIndex);
            } else {
                $this->StopZone($zoneIndex);
            }
            return;
        }

        if (preg_match('/^Day([1-7])(Morning|Evening)(Enabled|Zones)$/', $Ident, $matches)) {
            if ($matches[3] === 'Enabled') {
                SetValueBoolean($this->GetIDForIdent($Ident), (bool) $Value);
            } else {
                SetValueString($this->GetIDForIdent($Ident), $this->NormalizeZoneList((string) $Value));
            }
            $this->UpdatePlannerHtml();
            return;
        }

        throw new Exception('Ungültige Aktion: ' . $Ident);
    }

    public function CheckSchedules(): void
    {
        $this->ResetDailyRuntimeIfNeeded();

        if (!$this->GetBool('MasterSwitch') || !$this->GetBool('AutomaticMode')) {
            return;
        }

        $now = new DateTimeImmutable();
        $currentTime = $now->format('H:i');

        foreach ([self::SLOT_MORNING, self::SLOT_EVENING] as $slot) {
            $startTime = $this->GetSlotStartTime($slot);
            if ($currentTime !== $startTime) {
                continue;
            }

            $runKey = $now->format('Y-m-d') . '-' . $slot . '-' . $startTime;
            if ($this->ReadAttributeString('LastRunKey') === $runKey) {
                continue;
            }

            $this->WriteAttributeString('LastRunKey', $runKey);
            $this->StartSequence($slot);
        }
    }

    public function StartSequence(string $Slot): void
    {
        $slot = $this->NormalizeSlot($Slot);
        if (!$this->GetBool('MasterSwitch')) {
            $this->SetStatusText('Master aus - Sequenz nicht gestartet.');
            return;
        }

        if (!IPS_SemaphoreEnter('BWS_' . $this->InstanceID, 5000)) {
            $this->SetStatusText('Bewässerung läuft bereits.');
            return;
        }

        try {
            $zones = $this->BuildSequence($slot);
            if (count($zones) === 0) {
                $this->SetStatusText('Keine Zone für ' . $this->SlotCaption($slot) . ' fällig.');
                return;
            }

            $this->SetStatusText('Sequenz ' . $this->SlotCaption($slot) . ' läuft.');
            $this->RunSequentialZones($zones);
            $this->SetStatusText('Sequenz ' . $this->SlotCaption($slot) . ' beendet.');
        } finally {
            IPS_SemaphoreLeave('BWS_' . $this->InstanceID);
            $this->UpdatePlannerHtml();
        }
    }

    public function StartManualZone(int $ZoneIndex): void
    {
        if (!$this->GetBool('MasterSwitch')) {
            SetValueBoolean($this->GetIDForIdent('ManualZone' . $ZoneIndex), false);
            $this->SetStatusText('Master aus - manueller Start blockiert.');
            return;
        }

        if (!IPS_SemaphoreEnter('BWS_' . $this->InstanceID, 5000)) {
            SetValueBoolean($this->GetIDForIdent('ManualZone' . $ZoneIndex), false);
            $this->SetStatusText('Bewässerung läuft bereits.');
            return;
        }

        try {
            if ($this->CountActiveManualZones() >= 2) {
                SetValueBoolean($this->GetIDForIdent('ManualZone' . $ZoneIndex), false);
                $this->SetStatusText('Maximal zwei manuelle Zonen gleichzeitig.');
                return;
            }

            if ($this->LawnPartnerIsActive($ZoneIndex)) {
                SetValueBoolean($this->GetIDForIdent('ManualZone' . $ZoneIndex), false);
                $this->SetStatusText('Rasen rechts und Rasen links dürfen nicht gleichzeitig laufen.');
                return;
            }

            $zone = $this->GetZoneByIndex($ZoneIndex);
            if (!$zone || !$this->ZoneIsUsable($zone)) {
                SetValueBoolean($this->GetIDForIdent('ManualZone' . $ZoneIndex), false);
                $this->SetStatusText('Zone ' . $ZoneIndex . ' ist nicht vollständig konfiguriert.');
                return;
            }

            $this->OpenValve($zone);
            IPS_Sleep($this->Seconds($zone['TravelTime']) * 1000);
            $this->SetPump(true);
            $this->SetManualStart($ZoneIndex, time());
            SetValueBoolean($this->GetIDForIdent('ManualZone' . $ZoneIndex), true);
            $this->SetStatusText('Manuell aktiv: ' . $zone['Name']);
        } finally {
            IPS_SemaphoreLeave('BWS_' . $this->InstanceID);
            $this->UpdatePlannerHtml();
        }
    }

    public function StopZone(int $ZoneIndex): void
    {
        $zone = $this->GetZoneByIndex($ZoneIndex);
        if (!$zone || (int) $zone['ValveID'] <= 0) {
            return;
        }

        $this->ResetDailyRuntimeIfNeeded();
        $this->AddManualRuntime($ZoneIndex);
        if ($this->CountActiveManualZones($ZoneIndex) === 0) {
            $this->SetPump(false);
        }
        IPS_Sleep($this->Seconds($zone['TravelTime']) * 1000);
        $this->SetValve($zone, false);
        SetValueBoolean($this->GetIDForIdent('ManualZone' . $ZoneIndex), false);
        $this->SetStatusText('Zone gestoppt: ' . $zone['Name']);
        $this->UpdatePlannerHtml();
    }

    public function StopLawn(): void
    {
        foreach ([self::LAWN_RIGHT_ZONE, self::LAWN_LEFT_ZONE] as $zoneIndex) {
            $zone = $this->GetZoneByIndex($zoneIndex);
            if (!$zone) {
                continue;
            }

            $this->AddManualRuntime($zoneIndex);
            $this->SetValve($zone, false);
            $manualIdent = 'ManualZone' . $zoneIndex;
            if ($this->IdentExists($manualIdent)) {
                SetValueBoolean($this->GetIDForIdent($manualIdent), false);
            }
        }

        if ($this->CountActiveManualZones() === 0) {
            $this->SetPump(false);
        }

        if ($this->IdentExists('ManualLawn')) {
            SetValueBoolean($this->GetIDForIdent('ManualLawn'), false);
        }

        $this->SetStatusText('Rasen gestoppt.');
        $this->UpdatePlannerHtml();
    }

    public function StopAll(): void
    {
        $this->ResetDailyRuntimeIfNeeded();
        foreach (array_keys($this->GetManualStarts()) as $zoneIndex) {
            $this->AddManualRuntime((int) $zoneIndex);
        }

        $this->SetPump(false);
        IPS_Sleep($this->ReadPropertyInteger('DefaultValveTravelTime') * 1000);

        foreach ($this->GetZones() as $zone) {
            $this->SetValve($zone, false);
            $manualIdent = 'ManualZone' . (int) $zone['Index'];
            if ($this->IdentExists($manualIdent)) {
                SetValueBoolean($this->GetIDForIdent($manualIdent), false);
            }
        }

        if ($this->IdentExists('ManualLawn')) {
            SetValueBoolean($this->GetIDForIdent('ManualLawn'), false);
        }

        $this->SetStatusText('Alle Zonen gestoppt.');
        $this->UpdatePlannerHtml();
    }

    public function ResetDailyRuntime(): void
    {
        SetValueInteger($this->GetIDForIdent('PumpRuntimeToday'), 0);
        $this->WriteAttributeString('LastDailyReset', date('Y-m-d'));
        $this->UpdatePlannerHtml();
    }

    private function RunSequentialZones(array $entries): void
    {
        $currentZone = null;

        foreach ($entries as $entry) {
            if (!$this->GetBool('MasterSwitch')) {
                break;
            }

            if (($entry['Type'] ?? 'zone') === 'lawn') {
                $this->RunLawnEntry($entry, $currentZone);
                continue;
            }

            $nextZone = $entry['Zone'];
            $this->SwitchToZone($currentZone, $nextZone);
            $this->RunIrrigationRuntime((int) $entry['DurationSeconds']);
        }

        $this->SetPump(false);
        if ($currentZone !== null) {
            IPS_Sleep($this->Seconds($currentZone['TravelTime']) * 1000);
            $this->SetValve($currentZone, false);
        }
    }

    private function RunLawnEntry(array $entry, ?array &$currentZone): void
    {
        $zones = $entry['Zones'] ?? [];
        if (count($zones) < 2) {
            return;
        }

        $rightZone = $zones[0];
        $leftZone = $zones[1];
        $durationSeconds = (int) $entry['DurationSeconds'];
        $rightDuration = intdiv($durationSeconds, 2);
        $leftDuration = $durationSeconds - $rightDuration;

        $this->SwitchToZone($currentZone, $rightZone);
        $this->RunIrrigationRuntime($rightDuration);

        if (!$this->GetBool('MasterSwitch')) {
            return;
        }

        $this->SwitchToZone($currentZone, $leftZone);
        $this->RunIrrigationRuntime($leftDuration);
    }

    private function SwitchToZone(?array &$currentZone, array $nextZone): void
    {
        if ($currentZone === null) {
            $this->OpenValve($nextZone);
            IPS_Sleep($this->Seconds($nextZone['TravelTime']) * 1000);
            $this->SetPump(true);
        } else {
            $this->OpenValve($nextZone);
            IPS_Sleep($this->Seconds($nextZone['TravelTime']) * 1000);
            $this->SetValve($currentZone, false);
        }

        $currentZone = $nextZone;
    }

    private function RunIrrigationRuntime(int $durationSeconds): void
    {
        $remaining = max(1, $durationSeconds);
        while ($remaining > 0 && $this->GetBool('MasterSwitch')) {
            $step = min(10, $remaining);
            IPS_Sleep($step * 1000);
            $this->AddPumpRuntime($step);
            $remaining -= $step;
        }
    }

    private function BuildSequence(string $slot): array
    {
        $today = (int) date('N');
        if (!$this->GetDaySlotEnabled($today, $slot)) {
            return [];
        }

        $selected = $this->GetDaySlotZones($today, $slot);
        $orderKey = $slot === self::SLOT_MORNING ? 'MorningOrder' : 'EveningOrder';
        $intervalKey = $slot === self::SLOT_MORNING ? 'MorningInterval' : 'EveningInterval';
        $dayNumber = (int) floor(time() / 86400);
        $entries = [];
        $zonesByIndex = [];

        foreach ($this->GetZones() as $zone) {
            $zonesByIndex[(int) $zone['Index']] = $zone;
        }

        foreach ($zonesByIndex as $zone) {
            $index = (int) $zone['Index'];
            if (in_array($index, [self::LAWN_RIGHT_ZONE, self::LAWN_LEFT_ZONE], true)) {
                continue;
            }

            if (!$this->ZoneIsDue($zone, $selected, $intervalKey, $dayNumber)) {
                continue;
            }

            $entries[] = [
                'Type' => 'zone',
                'Zone' => $zone,
                'Order' => (int) ($zone[$orderKey] ?? 999),
                'DurationSeconds' => $this->GetZoneDurationSeconds($zone, $slot)
            ];
        }

        if ($this->LawnIsSelected($selected)) {
            $rightZone = $zonesByIndex[self::LAWN_RIGHT_ZONE] ?? null;
            $leftZone = $zonesByIndex[self::LAWN_LEFT_ZONE] ?? null;

            if (
                is_array($rightZone) &&
                is_array($leftZone) &&
                $this->ZoneIsDue($rightZone, [self::LAWN_RIGHT_ZONE], $intervalKey, $dayNumber) &&
                $this->ZoneIsDue($leftZone, [self::LAWN_LEFT_ZONE], $intervalKey, $dayNumber)
            ) {
                $entries[] = [
                    'Type' => 'lawn',
                    'Name' => 'Rasen',
                    'Zones' => [$rightZone, $leftZone],
                    'Order' => min((int) ($rightZone[$orderKey] ?? 999), (int) ($leftZone[$orderKey] ?? 999)),
                    'DurationSeconds' => $this->GetZoneDurationSeconds($rightZone, $slot)
                ];
            }
        }

        usort($entries, static function (array $a, array $b): int {
            return ((int) ($a['Order'] ?? 999)) <=> ((int) ($b['Order'] ?? 999));
        });

        return $entries;
    }

    private function OpenValve(array $zone): void
    {
        $this->SetValve($zone, true);
        $cyclesIdent = 'ValveCycles' . (int) $zone['Index'];
        SetValueInteger($this->GetIDForIdent($cyclesIdent), GetValueInteger($this->GetIDForIdent($cyclesIdent)) + 1);
    }

    private function SetValve(array $zone, bool $state): void
    {
        $valveID = (int) ($zone['ValveID'] ?? 0);
        $this->WriteKnxDpt1($valveID, $state);
        if ($valveID > 0 || !$state) {
            $this->SetActiveZone((int) $zone['Index'], $state);
        }
    }

    private function SetPump(bool $state): void
    {
        $pumpID = $this->ReadPropertyInteger('PumpID');
        $this->WriteKnxDpt1($pumpID, $state);
    }

    private function WriteKnxDpt1(int $instanceID, bool $state): void
    {
        if ($instanceID <= 0) {
            return;
        }

        KNX_WriteDPT1($instanceID, $state);
    }

    private function AddPumpRuntime(int $seconds): void
    {
        SetValueInteger($this->GetIDForIdent('PumpRuntimeToday'), GetValueInteger($this->GetIDForIdent('PumpRuntimeToday')) + $seconds);
        SetValueInteger($this->GetIDForIdent('PumpRuntimeTotal'), GetValueInteger($this->GetIDForIdent('PumpRuntimeTotal')) + $seconds);
    }

    private function SetActiveZone(int $zoneIndex, bool $state): void
    {
        $active = $this->GetActiveZoneIndexes();
        if ($state && !in_array($zoneIndex, $active, true)) {
            $active[] = $zoneIndex;
        } elseif (!$state) {
            $active = array_values(array_filter($active, static function (int $index) use ($zoneIndex): bool {
                return $index !== $zoneIndex;
            }));
        }

        sort($active);
        $this->WriteAttributeString('ActiveZoneIndexes', json_encode($active));
        $this->UpdateActiveZonesText();
        $this->UpdateLogicalLawnSwitch();
    }

    private function GetActiveZoneIndexes(): array
    {
        $active = json_decode($this->ReadAttributeString('ActiveZoneIndexes'), true);
        if (!is_array($active)) {
            return [];
        }

        $indexes = [];
        foreach ($active as $index) {
            $index = (int) $index;
            if ($index >= 1 && $index <= self::ZONE_COUNT && !in_array($index, $indexes, true)) {
                $indexes[] = $index;
            }
        }

        sort($indexes);
        return $indexes;
    }

    private function UpdateActiveZonesText(): void
    {
        if (!$this->IdentExists('ActiveZones')) {
            return;
        }

        $names = [];
        foreach ($this->GetActiveZoneIndexes() as $zoneIndex) {
            $zone = $this->GetZoneByIndex($zoneIndex);
            if ($zone) {
                $names[] = (string) $zone['Name'];
            }
        }

        SetValueString($this->GetIDForIdent('ActiveZones'), count($names) > 0 ? implode(', ', $names) : 'Keine');
        if ($this->IdentExists('ActiveLawnSide')) {
            SetValueString($this->GetIDForIdent('ActiveLawnSide'), $this->GetActiveLawnSideText());
        }
    }

    private function GetActiveLawnSideText(): string
    {
        $active = $this->GetActiveZoneIndexes();
        if (in_array(self::LAWN_LEFT_ZONE, $active, true)) {
            return 'Rasen links';
        }

        if (in_array(self::LAWN_RIGHT_ZONE, $active, true)) {
            return 'Rasen rechts';
        }

        return 'Keiner';
    }

    private function UpdateLogicalLawnSwitch(): void
    {
        if (!$this->IdentExists('ManualLawn')) {
            return;
        }

        $active = $this->GetActiveZoneIndexes();
        SetValueBoolean($this->GetIDForIdent('ManualLawn'), in_array(self::LAWN_RIGHT_ZONE, $active, true) || in_array(self::LAWN_LEFT_ZONE, $active, true));
    }

    private function SetManualStart(int $zoneIndex, int $timestamp): void
    {
        $starts = $this->GetManualStarts();
        $starts[(string) $zoneIndex] = $timestamp;
        $this->WriteAttributeString('ManualActiveStarts', json_encode($starts));
    }

    private function AddManualRuntime(int $zoneIndex): void
    {
        $starts = $this->GetManualStarts();
        $key = (string) $zoneIndex;
        if (!isset($starts[$key])) {
            return;
        }

        $seconds = max(0, time() - (int) $starts[$key]);
        unset($starts[$key]);
        $this->WriteAttributeString('ManualActiveStarts', json_encode($starts));

        if ($seconds > 0) {
            $this->AddPumpRuntime($seconds);
        }
    }

    private function GetManualStarts(): array
    {
        $starts = json_decode($this->ReadAttributeString('ManualActiveStarts'), true);
        return is_array($starts) ? $starts : [];
    }

    private function CountActiveManualZones(int $excludeZone = 0): int
    {
        $count = 0;
        for ($i = 1; $i <= self::ZONE_COUNT; $i++) {
            if ($i === $excludeZone || !$this->IdentExists('ManualZone' . $i)) {
                continue;
            }

            if (GetValueBoolean($this->GetIDForIdent('ManualZone' . $i))) {
                $count++;
            }
        }

        return $count;
    }

    private function LawnPartnerIsActive(int $zoneIndex): bool
    {
        $partnerIndex = 0;
        if ($zoneIndex === self::LAWN_RIGHT_ZONE) {
            $partnerIndex = self::LAWN_LEFT_ZONE;
        } elseif ($zoneIndex === self::LAWN_LEFT_ZONE) {
            $partnerIndex = self::LAWN_RIGHT_ZONE;
        }

        return $partnerIndex > 0 && $this->IdentExists('ManualZone' . $partnerIndex) && GetValueBoolean($this->GetIDForIdent('ManualZone' . $partnerIndex));
    }

    private function RegisterWebFrontVariables(): void
    {
        $this->RegisterVariableBoolean('MasterSwitch', 'Master-Switch', '~Switch', 10);
        $this->EnableAction('MasterSwitch');

        $this->RegisterVariableBoolean('AutomaticMode', 'Automatik', '~Switch', 20);
        $this->EnableAction('AutomaticMode');

        $this->RegisterVariableString('StatusText', 'Status', '', 30);
        $this->RegisterVariableString('ActiveZones', 'Aktive Kreise', '', 35);
        $this->RegisterVariableString('ActiveLawnSide', 'Aktiver Rasen-Kreis', '', 36);
        $this->RegisterVariableString('MorningStartTime', 'Startzeit morgens', '', 40);
        $this->EnableAction('MorningStartTime');
        $this->RegisterVariableString('EveningStartTime', 'Startzeit abends', '', 50);
        $this->EnableAction('EveningStartTime');
        $this->RegisterVariableInteger('LawnMorningDuration', 'Rasen Laufzeit morgens', 'BWS.Minutes', 52);
        $this->EnableAction('LawnMorningDuration');
        $this->RegisterVariableInteger('LawnEveningDuration', 'Rasen Laufzeit abends', 'BWS.Minutes', 54);
        $this->EnableAction('LawnEveningDuration');

        $this->RegisterVariableInteger('PumpRuntimeToday', 'Pumpenlaufzeit heute', 'BWS.Duration', 60);
        $this->RegisterVariableInteger('PumpRuntimeTotal', 'Pumpenlaufzeit gesamt', 'BWS.Duration', 70);
        $this->RegisterVariableString('PlannerHtml', 'Planer Übersicht', '~HTMLBox', 80);

        $position = 100;
        foreach ($this->GetZones() as $zone) {
            $index = (int) $zone['Index'];
            $name = (string) $zone['Name'];
            $this->RegisterVariableBoolean('ManualZone' . $index, 'Manuell ' . $name, '~Switch', $position++);
            $this->EnableAction('ManualZone' . $index);
            IPS_SetHidden($this->GetIDForIdent('ManualZone' . $index), in_array($index, [self::LAWN_RIGHT_ZONE, self::LAWN_LEFT_ZONE], true));
            $this->RegisterVariableInteger('ValveCycles' . $index, 'Ventilzyklen ' . $name, '', $position++);
        }

        $this->RegisterVariableBoolean('ManualLawn', 'Manuell Rasen', '~Switch', $position++);
        $this->EnableAction('ManualLawn');

        foreach (self::DAYS as $day => $caption) {
            foreach ([self::SLOT_MORNING => 'Morning', self::SLOT_EVENING => 'Evening'] as $slot => $part) {
                $this->RegisterVariableBoolean('Day' . $day . $part . 'Enabled', $caption . ' ' . $this->SlotCaption($slot) . ' aktiv', '~Switch', $position++);
                $this->EnableAction('Day' . $day . $part . 'Enabled');
                $this->RegisterVariableString('Day' . $day . $part . 'Zones', $caption . ' ' . $this->SlotCaption($slot) . ' Kreise', '', $position++);
                $this->EnableAction('Day' . $day . $part . 'Zones');
            }
        }
    }

    private function SyncInitialPlannerValues(): void
    {
        $this->EnsureLawnDurationValues();

        if ($this->ReadAttributeBoolean('VariablesInitialized')) {
            $this->UpdateActiveZonesText();
            $this->UpdateLogicalLawnSwitch();
            $this->UpdatePlannerHtml();
            return;
        }

        SetValueBoolean($this->GetIDForIdent('MasterSwitch'), $this->ReadPropertyBoolean('MasterEnabled'));
        SetValueBoolean($this->GetIDForIdent('AutomaticMode'), $this->ReadPropertyBoolean('AutomaticEnabled'));

        $morningID = $this->GetIDForIdent('MorningStartTime');
        if (GetValueString($morningID) === '') {
            SetValueString($morningID, $this->NormalizeTime($this->ReadPropertyString('MorningStartTime')));
        }

        $eveningID = $this->GetIDForIdent('EveningStartTime');
        if (GetValueString($eveningID) === '') {
            SetValueString($eveningID, $this->NormalizeTime($this->ReadPropertyString('EveningStartTime')));
        }

        $dayPlan = $this->GetDayPlan();
        foreach ($dayPlan as $row) {
            $day = (int) $row['Day'];
            $morningEnabledIdent = 'Day' . $day . 'MorningEnabled';
            if ($this->IdentExists($morningEnabledIdent)) {
                SetValueBoolean($this->GetIDForIdent($morningEnabledIdent), (bool) $row['MorningEnabled']);
                SetValueString($this->GetIDForIdent('Day' . $day . 'MorningZones'), $this->NormalizeZoneList((string) $row['MorningZones']));
                SetValueBoolean($this->GetIDForIdent('Day' . $day . 'EveningEnabled'), (bool) $row['EveningEnabled']);
                SetValueString($this->GetIDForIdent('Day' . $day . 'EveningZones'), $this->NormalizeZoneList((string) $row['EveningZones']));
            }
        }

        $this->WriteAttributeBoolean('VariablesInitialized', true);
        $this->UpdateActiveZonesText();
        $this->UpdateLogicalLawnSwitch();
        $this->UpdatePlannerHtml();
    }

    private function EnsureLawnDurationValues(): void
    {
        if (!$this->IdentExists('LawnMorningDuration') || !$this->IdentExists('LawnEveningDuration')) {
            return;
        }

        $lawnZone = $this->GetZoneByIndex(self::LAWN_RIGHT_ZONE);
        if (!$lawnZone) {
            return;
        }

        $morningID = $this->GetIDForIdent('LawnMorningDuration');
        if (GetValueInteger($morningID) <= 0) {
            SetValueInteger($morningID, max(1, (int) ($lawnZone['MorningDuration'] ?? 10)));
        }

        $eveningID = $this->GetIDForIdent('LawnEveningDuration');
        if (GetValueInteger($eveningID) <= 0) {
            SetValueInteger($eveningID, max(1, (int) ($lawnZone['EveningDuration'] ?? 10)));
        }
    }

    private function UpdatePlannerHtml(): void
    {
        if (!$this->IdentExists('PlannerHtml')) {
            return;
        }

        $rows = '';
        foreach (self::DAYS as $day => $caption) {
            $rows .= '<tr><td>' . $caption . '</td><td>' . $this->OnOff($this->GetDaySlotEnabled($day, self::SLOT_MORNING)) . '</td><td>' . htmlspecialchars($this->FormatZoneList($this->GetDaySlotZones($day, self::SLOT_MORNING))) . '</td><td>' . $this->OnOff($this->GetDaySlotEnabled($day, self::SLOT_EVENING)) . '</td><td>' . htmlspecialchars($this->FormatZoneList($this->GetDaySlotZones($day, self::SLOT_EVENING))) . '</td></tr>';
        }

        $html = '<style>table.bws{border-collapse:collapse;width:100%}.bws th,.bws td{border:1px solid #ddd;padding:6px;text-align:left}.bws th{background:#f4f4f4}.bws .on{color:#167a2f;font-weight:bold}.bws .off{color:#9b1c1c;font-weight:bold}</style>';
        $html .= '<table class="bws"><thead><tr><th>Tag</th><th>Morgens</th><th>Kreise</th><th>Abends</th><th>Kreise</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        SetValueString($this->GetIDForIdent('PlannerHtml'), $html);
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('BWS.Duration')) {
            IPS_CreateVariableProfile('BWS.Duration', 1);
            IPS_SetVariableProfileText('BWS.Duration', '', ' s');
        }

        if (!IPS_VariableProfileExists('BWS.Minutes')) {
            IPS_CreateVariableProfile('BWS.Minutes', 1);
            IPS_SetVariableProfileText('BWS.Minutes', '', ' min');
        }
    }

    private function ResetDailyRuntimeIfNeeded(): void
    {
        $today = date('Y-m-d');
        if ($this->ReadAttributeString('LastDailyReset') !== $today) {
            SetValueInteger($this->GetIDForIdent('PumpRuntimeToday'), 0);
            $this->WriteAttributeString('LastDailyReset', $today);
        }
    }

    private function ZoneIsUsable(array $zone): bool
    {
        return (bool) ($zone['Enabled'] ?? true) && (int) ($zone['ValveID'] ?? 0) > 0;
    }

    private function ZoneIsDue(array $zone, array $selected, string $intervalKey, int $dayNumber): bool
    {
        $index = (int) $zone['Index'];
        if (!in_array($index, $selected, true) || !$this->ZoneIsUsable($zone) || $this->SoilMoistureBlocks($zone)) {
            return false;
        }

        $interval = max(1, (int) ($zone[$intervalKey] ?? 1));
        return $dayNumber % $interval === 0;
    }

    private function LawnIsSelected(array $selected): bool
    {
        return in_array(self::LAWN_RIGHT_ZONE, $selected, true) || in_array(self::LAWN_LEFT_ZONE, $selected, true);
    }

    private function SoilMoistureBlocks(array $zone): bool
    {
        $sensorID = (int) ($zone['SoilMoistureID'] ?? 0);
        if ($sensorID <= 0) {
            return false;
        }

        $value = (float) GetValue($sensorID);
        return $value >= $this->ReadPropertyInteger('SoilMoistureThreshold');
    }

    private function GetZoneByIndex(int $index): ?array
    {
        foreach ($this->GetZones() as $zone) {
            if ((int) $zone['Index'] === $index) {
                return $zone;
            }
        }

        return null;
    }

    private function GetZones(): array
    {
        $zones = json_decode($this->ReadPropertyString('Zones'), true);
        if (!is_array($zones)) {
            return $this->DefaultZones();
        }

        return $this->NormalizeZones($zones);
    }

    private function NormalizeZones(array $zones): array
    {
        $defaults = $this->DefaultZones();
        $normalized = [];

        for ($i = 1; $i <= self::ZONE_COUNT; $i++) {
            $zone = $zones[$i - 1] ?? [];
            if (!is_array($zone)) {
                $zone = [];
            }

            $index = (int) ($zone['Index'] ?? $i);
            if ($index < 1 || $index > self::ZONE_COUNT) {
                $index = $i;
            }

            $normalized[] = array_merge($defaults[$index - 1], $zone, ['Index' => $index]);
        }

        usort($normalized, static function (array $a, array $b): int {
            return ((int) $a['Index']) <=> ((int) $b['Index']);
        });

        return $normalized;
    }

    private function GetDayPlan(): array
    {
        $plan = json_decode($this->ReadPropertyString('DayPlan'), true);
        return is_array($plan) ? $plan : $this->DefaultDayPlan();
    }

    private function GetBool(string $ident): bool
    {
        return $this->IdentExists($ident) ? GetValueBoolean($this->GetIDForIdent($ident)) : false;
    }

    private function GetSlotStartTime(string $slot): string
    {
        $ident = $slot === self::SLOT_MORNING ? 'MorningStartTime' : 'EveningStartTime';
        $fallback = $slot === self::SLOT_MORNING ? $this->ReadPropertyString('MorningStartTime') : $this->ReadPropertyString('EveningStartTime');
        return $this->IdentExists($ident) ? $this->NormalizeTime(GetValueString($this->GetIDForIdent($ident))) : $this->NormalizeTime($fallback);
    }

    private function GetSlotDurationSeconds(string $slot): int
    {
        $minutes = $slot === self::SLOT_MORNING ? $this->ReadPropertyInteger('MorningDuration') : $this->ReadPropertyInteger('EveningDuration');
        return max(1, $minutes) * 60;
    }

    private function GetZoneDurationSeconds(array $zone, string $slot): int
    {
        if ((int) ($zone['Index'] ?? 0) === self::LAWN_RIGHT_ZONE) {
            return $this->GetLawnDurationSeconds($slot);
        }

        $durationKey = $slot === self::SLOT_MORNING ? 'MorningDuration' : 'EveningDuration';
        $minutes = (int) ($zone[$durationKey] ?? 0);
        if ($minutes <= 0) {
            return $this->GetSlotDurationSeconds($slot);
        }

        return $minutes * 60;
    }

    private function GetLawnDurationSeconds(string $slot): int
    {
        $ident = $slot === self::SLOT_MORNING ? 'LawnMorningDuration' : 'LawnEveningDuration';
        if ($this->IdentExists($ident)) {
            return max(1, GetValueInteger($this->GetIDForIdent($ident))) * 60;
        }

        $lawnZone = $this->GetZoneByIndex(self::LAWN_RIGHT_ZONE);
        if ($lawnZone) {
            $durationKey = $slot === self::SLOT_MORNING ? 'MorningDuration' : 'EveningDuration';
            return max(1, (int) ($lawnZone[$durationKey] ?? 10)) * 60;
        }

        return $this->GetSlotDurationSeconds($slot);
    }

    private function GetDaySlotEnabled(int $day, string $slot): bool
    {
        $ident = 'Day' . $day . ($slot === self::SLOT_MORNING ? 'Morning' : 'Evening') . 'Enabled';
        return $this->IdentExists($ident) ? GetValueBoolean($this->GetIDForIdent($ident)) : true;
    }

    private function GetDaySlotZones(int $day, string $slot): array
    {
        $ident = 'Day' . $day . ($slot === self::SLOT_MORNING ? 'Morning' : 'Evening') . 'Zones';
        $value = $this->IdentExists($ident) ? GetValueString($this->GetIDForIdent($ident)) : '1,2,3,4,5,6,7';
        return $this->ParseZoneList($value);
    }

    private function NormalizeSlot(string $slot): string
    {
        $normalized = strtolower($slot);
        return $normalized === self::SLOT_EVENING ? self::SLOT_EVENING : self::SLOT_MORNING;
    }

    private function NormalizeTime(string $value): string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $matches)) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        return '06:00';
    }

    private function NormalizeZoneList(string $value): string
    {
        return implode(',', $this->ParseZoneList($value));
    }

    private function FormatZoneList(array $zones): string
    {
        $labels = [];
        foreach ($zones as $zoneIndex) {
            if (in_array($zoneIndex, [self::LAWN_RIGHT_ZONE, self::LAWN_LEFT_ZONE], true)) {
                if (!in_array('Rasen', $labels, true)) {
                    $labels[] = 'Rasen';
                }
                continue;
            }

            $labels[] = (string) $zoneIndex;
        }

        return implode(', ', $labels);
    }

    private function ParseZoneList(string $value): array
    {
        $zones = [];
        foreach (preg_split('/[^0-9]+/', $value) as $part) {
            $index = (int) $part;
            if ($index >= 1 && $index <= self::ZONE_COUNT && !in_array($index, $zones, true)) {
                $zones[] = $index;
            }
        }

        return $zones;
    }

    private function SlotCaption(string $slot): string
    {
        return $slot === self::SLOT_MORNING ? 'morgens' : 'abends';
    }

    private function OnOff(bool $state): string
    {
        return $state ? '<span class="on">ein</span>' : '<span class="off">aus</span>';
    }

    private function Seconds($value): int
    {
        return max(1, (int) $value);
    }

    private function SetStatusText(string $text): void
    {
        if ($this->IdentExists('StatusText')) {
            SetValueString($this->GetIDForIdent('StatusText'), $text);
        }
    }

    private function IdentExists(string $ident): bool
    {
        try {
            $this->GetIDForIdent($ident);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function DefaultZones(): array
    {
        $zones = [];
        for ($i = 1; $i <= self::ZONE_COUNT; $i++) {
            $name = 'Zone ' . $i;
            if ($i === self::LAWN_RIGHT_ZONE) {
                $name = 'Rasen rechts';
            } elseif ($i === self::LAWN_LEFT_ZONE) {
                $name = 'Rasen links';
            }

            $zones[] = [
                'Index' => $i,
                'Name' => $name,
                'Enabled' => true,
                'ValveID' => 0,
                'TravelTime' => 7,
                'MorningDuration' => 10,
                'EveningDuration' => 10,
                'SoilMoistureID' => 0,
                'MorningInterval' => 1,
                'EveningInterval' => 1,
                'MorningOrder' => $i,
                'EveningOrder' => $i
            ];
        }

        return $zones;
    }

    private function DefaultDayPlan(): array
    {
        $plan = [];
        for ($day = 1; $day <= 7; $day++) {
            $plan[] = [
                'Day' => $day,
                'MorningEnabled' => true,
                'MorningZones' => '1,2,3,4,5,6,7',
                'EveningEnabled' => true,
                'EveningZones' => '1,2,3,4,5,6,7'
            ];
        }

        return $plan;
    }
}
