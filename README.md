# Bewässerungssteuerung für IP-Symcon 9

Dieses Repository enthält ein IP-Symcon-Modul für eine Bewässerungssteuerung mit 7 Beregnungszonen, Motorkugelhähnen, Pumpensteuerung, Tagesplaner, optionaler Bodenfeuchteprüfung und Laufzeitzählern.

## Funktionen

- 7 frei benennbare Bewässerungszonen
- Ventil-ID und Verfahrzeit je Zone
- Pumpen-ID über Boolean-Variable
- Master-Switch und Automatik-Switch im WebFront
- optionaler Bodenfeuchtesensor je Zone
- manuelle Steuerung einzelner Zonen
- zwei automatische Sequenzen pro Tag: morgens und abends
- Startzeiten im WebFront editierbar
- Auswahl der Kreise pro Wochentag und Startzeit im WebFront
- freie Reihenfolge je Sequenz über Modulkonfiguration
- Intervall je Zone und Sequenz, zum Beispiel jeden 2. oder 3. Tag
- maximal zwei offene Zonen während der 10-Sekunden-Überlappung
- Pumpenlaufzeit heute und gesamt
- Anzahl der Öffnungszyklen pro Motorkugelhahn

## Schaltlogik

Einschalten:

1. Ventil öffnen
2. Verfahrzeit warten
3. Pumpe einschalten

Ausschalten:

1. Pumpe ausschalten
2. Verfahrzeit warten
3. Ventil schließen

Sequenzwechsel:

1. nächstes Ventil öffnen
2. Verfahrzeit des nächsten Ventils warten
3. vorheriges Ventil schließen
4. bei Bedarf Restzeit bis zur konfigurierten Überlappung warten

## Installation in IP-Symcon

1. Repository in GitLab anlegen und diese Dateien hochladen.
2. In IP-Symcon die Modulverwaltung öffnen.
3. Die GitLab-Repository-URL als neues Modul hinzufügen.
4. Eine neue Instanz vom Typ `Bewässerungssteuerung` erstellen.
5. Pumpen-ID, Ventil-IDs, Namen, Verfahrzeiten, Reihenfolgen und Intervalle konfigurieren.

## WebFront-Bedienung

Im WebFront stehen unter anderem diese Variablen bereit:

- `Master-Switch`
- `Automatik`
- `Startzeit morgens`
- `Startzeit abends`
- je Zone ein manueller Schalter
- je Wochentag und Startzeit ein Aktiv-Schalter
- je Wochentag und Startzeit eine Kreisliste, zum Beispiel `1,3,5`
- `Planer Übersicht`
- `Pumpenlaufzeit heute`
- `Pumpenlaufzeit gesamt`
- `Ventilzyklen Zone ...`

Die Kreisliste wird als kommagetrennte Liste gepflegt. Ungültige Werte werden automatisch entfernt.

## Hinweise

- Die Pumpen- und Ventilvariablen müssen Boolean-Variablen sein.
- Die Steuerung verwendet `SetValueBoolean($ID, true)` und `SetValueBoolean($ID, false)`.
- Bodenfeuchtewerte werden als Prozentwert erwartet. Ist der Sensorwert größer oder gleich der konfigurierten Schwelle, wird die Zone übersprungen.
- Die Tagesintervalle werden deterministisch anhand des Tageszählers berechnet. Bei Intervall `2` läuft eine Zone jeden zweiten Tag, bei `3` jeden dritten Tag.

