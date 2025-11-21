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
        $this->RegisterPropertyInteger("MaxParallelZones", 2);
        $this->RegisterPropertyString("ZoneList", "[]");

        // default start times (properties)
        $this->RegisterPropertyString("Sequence1Start", "06:00");
        $this->RegisterPropertyString("Sequence2Start", "20:00");
        $this->RegisterPropertyBoolean("Sequence1Enabled", false);
        $this->RegisterPropertyBoolean("Sequence2Enabled", false);
        $this->RegisterPropertyString("Sequence1Order", "");
        $this->RegisterPropertyString("Sequence2Order", "");

        $this->RegisterTimer("PumpOnTimer", 0, 'IRR_PumpOnTimer($_IPS[\'TARGET\']);');
        $this->RegisterTimer("SequenceTick", 0, 'IRR_SequenceTick($_IPS[\'TARGET\']);');

        $this->RegisterAttributeString("SequenceState", json_encode([]));
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterWebFrontVariables();
        $this->UpdateSequenceEndLabels();
    }

    private function RegisterWebFrontVariables(): void
    {
        // Startzeit-Sequenz 1
        if (@IPS_GetObjectIDByIdent("Sequence1StartVar", $this->InstanceID) === false) {
            $this->RegisterVariableString("Sequence1StartVar", "Startzeit Sequenz 1", "");
        }
        SetValue($this->GetIDForIdent("Sequence1StartVar"), $this->ReadPropertyString("Sequence1Start"));
        $this->EnableAction("Sequence1StartVar");

        // Startzeit-Sequenz 2
        if (@IPS_GetObjectIDByIdent("Sequence2StartVar", $this->InstanceID) === false) {
            $this->RegisterVariableString("Sequence2StartVar", "Startzeit Sequenz 2", "");
        }
        SetValue($this->GetIDForIdent("Sequence2StartVar"), $this->ReadPropertyString("Sequence2Start"));
        $this->EnableAction("Sequence2StartVar");

        // Endzeit-Anzeige
        if (@IPS_GetObjectIDByIdent("Sequence1End", $this->InstanceID) === false) {
            $this->RegisterVariableString("Sequence1End", "Ende Sequenz 1", "");
        }
        if (@IPS_GetObjectIDByIdent("Sequence2End", $this->InstanceID) === false) {
            $this->RegisterVariableString("Sequence2End", "Ende Sequenz 2", "");
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === "Sequence1StartVar") {
            IPS_SetProperty($this->InstanceID, "Sequence1Start", $Value);
            IPS_ApplyChanges($this->InstanceID);
            SetValue($this->GetIDForIdent("Sequence1StartVar"), $Value);
            return;
        }
        if ($Ident === "Sequence2StartVar") {
            IPS_SetProperty($this->InstanceID, "Sequence2Start", $Value);
            IPS_ApplyChanges($this->InstanceID);
            SetValue($this->GetIDForIdent("Sequence2StartVar"), $Value);
            return;
        }
    }

    private function UpdateSequenceEndLabels(): void
    {
        if ($this->GetIDForIdent("Sequence1End") !== false)
            SetValue($this->GetIDForIdent("Sequence1End"), "");
        if ($this->GetIDForIdent("Sequence2End") !== false)
            SetValue($this->GetIDForIdent("Sequence2End"), "");
    }
}
?>
