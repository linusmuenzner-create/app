# MVP testen – Checkliste

Hake nach der Installation diese Punkte ab. Dauer: ca. 10 Minuten.

## 1. Login & Sicherheit
- [ ] <https://dirks3n.munzner.net> → Login-Seite erscheint, Schloss im Browser.
- [ ] `http://dirks3n.munzner.net` (ohne s) → leitet automatisch auf https um.
- [ ] Falsches Passwort → „Benutzername oder Passwort falsch.“
- [ ] <https://dirks3n.munzner.net/setup.php> → „Einrichtung bereits abgeschlossen“.
- [ ] <https://dirks3n.munzner.net/../private/config.php> bzw. `/private/config.php` → **nicht erreichbar** (Fehler 403/404).
- [ ] Profil → Server: PHP ✓, Laufzeit ✓, HTTPS ✓.

## 2. Datenempfang
- [ ] Kurzbefehl „dirks3n Sync“ ausführen → Mitteilung „… Werte gespeichert“.
- [ ] App öffnen → Heute: Schlaf, HRV, Ruhepuls, Schritte zeigen Werte.
- [ ] Kurzbefehl nochmal ausführen → Werte bleiben gleich (keine Doppelzählung).
- [ ] Unten auf der Heute-Seite: „Letzte Gesundheitsdaten: gerade eben“.

## 3. Tägliche Trainingsentscheidung
- [ ] Heute → Karte „Dein Training“ zeigt Art, Intensität, Dauer, Uhrzeit, Begründung und Übungen.
- [ ] Ohne API-Schlüssel steht am Ende der Begründung „(Regel-Modus …)“ – das ist richtig.
- [ ] Mit API-Schlüssel: „Neu entscheiden“ → optional „Bin müde“ eingeben → neue Entscheidung „von der KI“.
- [ ] Nach 3× „Neu entscheiden“ ist der Knopf weg (Kostenschutz).
- [ ] Profil → KI: Kosten werden hochgezählt (pro Entscheidung ca. 0,01 $).

## 4. Dashboard / Statistik
- [ ] Statistik → Woche / Monat / 3 Monate / Jahr umschalten.
- [ ] Auf ein Diagramm tippen → Tooltip mit Datum und Wert.
- [ ] „Als Tabelle“ aufklappen → Zahlen sichtbar.
- [ ] Heute → Pfeil links → gestrige Werte (Tagesansicht).

## 5. Kalender & Cron
- [ ] Profil → Kalender-Link einfügen → „Kalender jetzt laden“ → „X Termine geladen“.
- [ ] Heute → Termine von heute sind sichtbar; die Entscheidung schlägt eine freie Uhrzeit vor.
- [ ] Cron-URL im Browser aufrufen → Text mit „Kalender: …“ und „Entscheidung: …“.

## 6. Fitbit (Google Health)
- [ ] Profil → Fitbit → **Mit Google verbinden** → nach dem Google-Login erscheint „Fitbit verbunden“.
- [ ] Profil → Fitbit zeigt **Charge 6**, Akku und „Uhr → App“.
- [ ] Heute: Schlaf, HRV, Ruhepuls, Schritte, Puls Ø kommen von Fitbit; Karte **„Puls heute“** zeigt den Tagesverlauf.
- [ ] Statistik → **Herzfrequenz** zeigt Ø pro Tag mit Spanne.
- [ ] Nach 1–3 Stunden: Profil → Fitbit → „Verlauf (90 Tage)“ steht auf **100 %**.
- [ ] Cron-URL im Browser → Zeile „Fitbit: Fitbit-Daten aktualisiert (… Verlauf … %)“.
- [ ] Ein Training, das Fitbit und Apple Health beide melden, erscheint nur **einmal**.
- [ ] (Bei „Extern + Testing“, nach 7 Tagen) Gelber Hinweis „Neu verbinden“ → antippen → wieder verbunden.

## Wenn etwas nicht klappt
- Fehlertexte stehen in `private/logs/app.log` und `private/logs/php-error.log` (per FTP herunterladen).
- Schick mir die letzten Zeilen (ohne Passwörter/Tokens), dann finden wir den Fehler.
