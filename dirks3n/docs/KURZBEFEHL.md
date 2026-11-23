# Apple Health → dirks3n mit der Kurzbefehle-App (kostenlos)

Der Kurzbefehl liest deine Gesundheitsdaten aus Apple Health und schickt sie an deinen Server.
Weil deine Google-Health-App (und ab Dezember Garmin Connect) ihre Daten **nach Apple Health**
schreibt, kommen darüber **alle Quellen** an.

> **Ehrlicher Hinweis:** Das Einrichten dauert ca. 20–30 Minuten, weil man den Kurzbefehl einmal
> von Hand zusammenklicken muss. Danach läuft er jeden Morgen automatisch.
> Die Namen der Aktionen können je nach iOS-Version leicht abweichen. Such im Zweifel in der
> Aktionsliste nach „Health“.

---

## Teil A – Vorbereitung

1. App **Kurzbefehle** öffnen (vorinstalliert; sonst kostenlos im App Store).
2. In dirks3n → **Profil → iPhone-Kurzbefehl** findest du **URL** und **Token**
   („Anzeigen“ → „Kopieren“). Die brauchst du gleich.

---

## Teil B – Den Kurzbefehl bauen

Kurzbefehle → **+** (neuer Kurzbefehl) → oben Namen tippen: **„dirks3n Sync“**.

### 1. Messwerte suchen (8 Aktionen)

Füge 8-mal die Aktion **„Health-Messwerte suchen“** hinzu
(englisch: *Find Health Samples*). Stelle jede so ein:

| # | Typ | Filter „Startdatum“ | Gruppieren nach | Name der Variable* |
|---|---|---|---|---|
| 1 | **Herzfrequenzvariabilität** | liegt in den letzten **3 Tagen** | – | HRV |
| 2 | **Ruheherzfrequenz** | letzten 3 Tagen | – | Ruhepuls |
| 3 | **Schritte** | letzten 3 Tagen | **Tag** | Schritte |
| 4 | **Aktive Energie** | letzten 3 Tagen | **Tag** | Aktiv |
| 5 | **Ruheenergie** | letzten 3 Tagen | **Tag** | Ruhe |
| 6 | **Gewicht** | letzten 3 Tagen | – | Gewicht |
| 7 | **Schlafanalyse** | letzten **2 Tagen** | – | Schlaf |
| 8 | **Trainings** (Workouts) | letzten 3 Tagen | – | Training |

\* Umbenennen: Auf das blaue Ergebnis-Feld der Aktion tippen → **Umbenennen**. Das macht es später übersichtlicher.

Bei „Sortieren nach“ nichts ändern, bei „Limit“ **aus**.

> „Gruppieren nach Tag“ ist bei Schritten und Energie wichtig: Dann rechnet Apple Health die
> Tagessumme selbst aus und zählt iPhone und Uhr nicht doppelt.

### 2. Alles in einen Text schreiben

Aktion **„Text“** hinzufügen. Schreib genau diese Zeilen hinein. Überall, wo **[ … ]** steht,
fügst du die Variable ein: Tippe an die Stelle → über der Tastatur die Variable wählen
(z. B. „HRV“) → **nochmal auf die eingefügte Variable tippen** → die richtige **Eigenschaft** wählen
(Wert, Startdatum, Enddatum, Typ).

```
@hrv.value
[HRV → Wert]
@hrv.start
[HRV → Startdatum]
@resting_hr.value
[Ruhepuls → Wert]
@resting_hr.start
[Ruhepuls → Startdatum]
@steps.value
[Schritte → Wert]
@steps.start
[Schritte → Startdatum]
@active_kcal.value
[Aktiv → Wert]
@active_kcal.start
[Aktiv → Startdatum]
@basal_kcal.value
[Ruhe → Wert]
@basal_kcal.start
[Ruhe → Startdatum]
@weight.value
[Gewicht → Wert]
@weight.start
[Gewicht → Startdatum]
@sleep.value
[Schlaf → Wert]
@sleep.start
[Schlaf → Startdatum]
@sleep.end
[Schlaf → Enddatum]
@workout.type
[Training → Typ]
@workout.start
[Training → Startdatum]
@workout.end
[Training → Enddatum]
```

**Datumsformat (wichtig):** Bei jedem *Startdatum* und *Enddatum* auf die Variable tippen →
**Datumsformat: ISO 8601** und **„Uhrzeit einschließen“** an.
(Falls ISO 8601 fehlt: „Kurz“ + Uhrzeit geht auch. dirks3n versteht auch `23.11.2026, 06:01`.)

Wichtig: Jede Überschrift (`@…`) steht **allein in einer Zeile**, und die Variable kommt in die Zeile darunter.

Wenn bei einer Eigenschaft wie „Typ“ bei Trainings ein anderer Name steht (z. B. „Trainingsart“), nimm diesen.
Wenn du Trainings mit Kalorien senden willst, kannst du optional ergänzen:
```
@workout.energy
[Training → Aktive Energie]
```

### 3. An den Server schicken

Aktion **„Inhalte von URL abrufen“** hinzufügen:
- URL: deine Adresse aus dirks3n (z. B. `https://dirks3n.munzner.net/ingest.php`)
- Aufklappen (**„Mehr anzeigen“**):
  - **Methode:** `POST`
  - **Header:** neuer Header → Schlüssel `Authorization`, Wert `Bearer DEIN-TOKEN` (aus dirks3n kopiert)
  - **Anfragetext:** **Datei** → Variable **„Text“** auswählen

### 4. Rückmeldung anzeigen

- Aktion **„Wörterbuchwert abrufen“** → Schlüssel `message` aus „Inhalte der URL“.
- Aktion **„Mitteilung anzeigen“** → Wörterbuchwert.

Fertig. Oben auf ▶︎ tippen. Beim ersten Mal fragt iOS nach **Health-Zugriff** → **alle erlauben**.
Es sollte eine Mitteilung kommen wie: „124 Werte gespeichert. Trainingsentscheidung wird erstellt …“

---

## Teil C – Jeden Morgen automatisch

Health-Daten sind gesperrt, solange dein iPhone gesperrt ist. Darum starten wir den Kurzbefehl, wenn du
den Wecker ausschaltest (dann ist das iPhone entsperrt und dein Schlaf fertig erfasst):

1. Kurzbefehle → unten **Automation** → **+** → **Wecker**.
2. **„Wird gestoppt“** → **Beliebiger Wecker** (oder dein Morgenwecker).
3. **„Sofort ausführen“** wählen (nicht „Vor Ausführen bestätigen“) → Weiter.
4. Aktion **„Kurzbefehl ausführen“** → **dirks3n Sync** → Fertig.

Optional eine zweite Automation, z. B. **Tageszeit 21:00** → „Sofort ausführen“ → dirks3n Sync.
Dann landen auch Trainings vom Abend zeitnah in der App. Läuft sie, während das iPhone gesperrt ist,
kommen einfach keine Daten an, und beim nächsten Mal wird alles nachgeholt (die letzten 3 Tage
werden jedes Mal mitgeschickt, doppelte Werte erkennt der Server).

---

## Einmalig: Verlauf importieren (für bessere Trends)

Damit die Bereitschaft vom ersten Tag an Vergleichswerte hat:
Kurzbefehl **duplizieren** → in der Kopie bei allen 8 Suchen „letzten **60 Tage**“ einstellen →
einmal ausführen → Kopie löschen. (Bei sehr vielen Daten kann das 1–2 Minuten dauern.)

---

## Garmin ab Dezember

Garmin Connect-App → **Mehr → Einstellungen → Verbundene Apps → Apple Health** → alles freigeben.
Fertig – der Kurzbefehl bringt die Garmin-Werte (Schlaf, HRV, Ruhepuls, Trainings) automatisch mit.
Wenn Garmin und Google gleichzeitig Schlaf liefern, zählt dirks3n Überschneidungen nicht doppelt.

---

## Probleme?

| Meldung / Problem | Lösung |
|---|---|
| „Token falsch“ | Header muss `Authorization` heißen, Wert `Bearer ` + Token (mit Leerzeichen nach Bearer) |
| „0 Werte gespeichert“ | Health-Zugriff prüfen: Einstellungen → Health → Datenzugriff → Kurzbefehle |
| „Hinweise: 3“ | Einzelne Werte waren unlesbar (z. B. Datumsformat). Datumsformat auf ISO 8601 stellen |
| Schlaf fehlt | Schlaf-Daten kommen erst nach dem Aufwachen. Prüfe, ob deine Google-Health-App Schlaf an Apple Health weitergibt |
| Nichts passiert morgens | Automation auf „Sofort ausführen“ stellen |
