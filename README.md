# IrrigationControl – Bewässerungssteuerung für IP-Symcon 8.1

Dieses Modul steuert eine Bewässerungsanlage über KNX-Motorkugelhähne und eine KNX-Pumpe.

## Funktionen

- 7 frei benennbare Bewässerungszonen
- KNX-Steuerung über `KNX_WriteDPT1'
- Achtung! Die KNX DPT 1 Instanz des Master Switch MUSS zwingend gelesen werden können!

## Installation

1. Repository in IP-Symcon als Modul hinzufügen
2. Instanz "IrrigationControl" kann nun im Objektbaum hinzugefügt werden
3. In der Instanz kann der Master, die Pumpe, jeder Motorkugelhahn als KNX DPT 1 angegeben werden. Ebenso kann die Verfahrzeit der Motorkugelhähne als globaler Wert definiert werden
4. Im Anschluss stehen die Funktionen:
  - IRR_Master
  - IRR_Pump
  - IRR_AllOff
  - IRR_SwitchZone
  - IRR_PumpOnTimer
  - IRR_GetZones
zur Verfügung

Wird eine Zone eingeschaltet, wird zuerst der Kugelhahne geöffnet, nach der Verfahrzeit wird die Pumpe gestartet.
Beim Einschalten einer weiteren Zone wird nur der Kugelhahn geöffnet. 
Beim Ausschalten wird erst die Pumpe ausgeschaltet, dann der Hahn verfahren. Sind mehrer Hähne offen, wird die Pumpe erst beim Ausschalten des letzten Hahnes ausgeschaltet.


✅ 1. IRR_Master(…)
Master EIN/AUS schalten
Syntax
IRR_Master(int $InstanceID, bool $State);

Parameter
Parameter	Typ	Bedeutung
$InstanceID	int	ID der IrrigationControl-Instanz
$State	bool	true = Master EIN, false = Master AUS
Beschreibung

Schaltet die Master-KNX-DPT1-Instanz.

Wenn Master EIN ist, wird alles abgeschaltet (AllOff()).

✅ 2. IRR_Pump(…)
Pumpe EIN/AUS schalten
Syntax
IRR_Pump(int $InstanceID, bool $State);

Parameter
Parameter	Typ	Bedeutung
$InstanceID	int	ID der IrrigationControl-Instanz
$State	bool	true = Pumpe EIN, false = Pumpe AUS
✅ 3. IRR_SwitchZone(…)
Eine Zone EIN/AUS schalten
Syntax
IRR_SwitchZone(int $InstanceID, int $ZoneIndex, bool $State);

Parameter
Parameter	Typ	Bedeutung
$InstanceID	int	Instanz-ID
$ZoneIndex	int	Index der Zone (0–x)
$State	bool	true = EIN, false = AUS
Beschreibung

⏺ prüft automatisch:

Master nicht aktiv

Parallelitätsregeln (Modell B)

MaxParallelZones

KNX Ventil-Instanz gültig

⏺ EIN:

setzt Ventil unmittelbar

startet bei erster Zone den Pumpen-Timer (TravelTime)

⏺ AUS:

schaltet Ventil ab

wenn letzte Zone → Pumpe AUS

✅ 4. IRR_RunSequence(…)
Sequenz starten
Syntax
IRR_RunSequence(int $InstanceID, int $SequenceNumber);

Parameter
Parameter	Typ	Bedeutung
$SequenceNumber	int	1 oder 2
Beschreibung

Prüft Master

Lädt ZoneList & Sequenzorder

Startet Schrittmotor (SequenceTick)

Abarbeitung nicht-blockierend per Timer

✅ 5. IRR_GetZones()
Zonenstatus auslesen
Syntax
$zones = IRR_GetZones(int $InstanceID);

Rückgabe (array)
[
  {
    "Index": 0,
    "Name": "Zone 1",
    "Enabled": true,
    "State": true,
    "Ventil": 12345
  },
  ...
]

📌 6. Automatisch verwendete Timer-Befehle
PumpOnTimer
IRR_PumpOnTimer(int $InstanceID);


Schaltet nach Ablauf der Verfahrzeit die Pumpe EIN.

SequenceTimer / SequenceTick
IRR_SequenceTick(int $InstanceID);


Schrittmotor für Sequenzen.

💡 7. Welche Befehle kannst du im WebFront direkt ausführen?

✓ Master an/aus
✓ Pumpe an/aus
✓ Sequenz 1 / 2 ausführen
✓ Zonen manuell schalten

💡 8. Welche Befehle kannst du in einem PHP-Skript aufrufen?
Beispiel: Zone 0 einschalten
IRR_SwitchZone(12345, 0, true);

Beispiel: Sequenz 2 starten
IRR_RunSequence(12345, 2);

Beispiel: Master AUS
IRR_Master(12345, false);
