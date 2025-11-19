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
