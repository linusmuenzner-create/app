# Fitbit Charge 6 anbinden (Google Health API)

Dauer: ca. 30 Minuten. Danach holt dirks3n deine Fitbit-Daten **automatisch jede Stunde** –
ohne Kurzbefehl, ohne Zusatz-App.

> **Was ist was?**
> - **Google Health API** = die offizielle Schnittstelle, über die Programme (mit deiner Erlaubnis)
>   deine Fitbit-Daten lesen dürfen. Sie ersetzt die alte Fitbit-Web-API (abgeschaltet seit 2.11.2026).
> - **OAuth 2.0** = „Mit Google anmelden“. Du gibst dirks3n einmal die Erlaubnis; dirks3n bekommt
>   dafür einen Schlüssel (Token), den es **verschlüsselt** in deiner Datenbank speichert.
>   Dein Google-Passwort sieht dirks3n nie.
> - **Google Cloud Console** = die Seite, auf der du dirks3n bei Google als „App“ anmeldest.

---

## Was du vorher brauchst

- Deine Charge 6 ist mit deinem **Google-Konto** verbunden (in der Fitbit-/Google-Health-App: Profil → Konto).
- dirks3n läuft bereits (siehe ANLEITUNG.md).
- Am Computer: einen Browser, in dem du mit **demselben Google-Konto** angemeldet bist.

---

## Teil A – Die App bei Google anmelden (Google Cloud Console)

### A1. Projekt anlegen
1. <https://console.cloud.google.com> öffnen und mit deinem Google-Konto anmelden.
   Beim ersten Mal: Nutzungsbedingungen akzeptieren. **Eine Kreditkarte ist nicht nötig.**
2. Oben links auf die Projektauswahl → **Neues Projekt**.
3. Name: `dirks3n` → **Erstellen**. Danach oben sicherstellen, dass `dirks3n` ausgewählt ist.

### A2. Google Health API einschalten
1. Links im Menü (☰) → **APIs & Dienste** → **Bibliothek**.
2. Nach **„Google Health API“** suchen → anklicken → **Aktivieren**.

### A3. Anmeldebildschirm einrichten („Google Auth Platform“)
1. Links → **Google Auth Platform** (oder: APIs & Dienste → OAuth-Zustimmungsbildschirm).
   Erscheint „Google Auth Platform not configured yet“ → **Jetzt starten / Get started**.
2. **App-Informationen:** App-Name `dirks3n`, Support-E-Mail = deine Gmail-Adresse → Weiter.
3. **Zielgruppe (Audience)** – wichtig:
   - Wird **„Persönlich / Personal“** angeboten → **das wählen**. Dann gilt: keine Prüfung durch
     Google, keine Warnseite, und die Verbindung läuft **nicht** nach 7 Tagen ab. 🎉
   - Sonst **„Extern / External“** wählen (nicht „Intern“ – das gibt es nur für Firmenkonten).
4. **Kontaktdaten:** deine E-Mail → Weiter → Richtlinien zustimmen → **Erstellen**.

### A4. Dich als Testnutzer eintragen (nur bei „Extern“)
1. Google Auth Platform → **Zielgruppe / Audience**.
2. Der Veröffentlichungsstatus steht auf **„Testen / Testing“** → **so lassen!**
   ⚠️ **Nicht** auf „App veröffentlichen“ klicken (siehe „Restricted-Scopes“ unten).
3. Unter **Testnutzer** → **+ Nutzer hinzufügen** → deine Gmail-Adresse → Speichern.

### A5. Berechtigungen (Scopes) eintragen
1. Google Auth Platform → **Datenzugriff / Data Access** → **Bereiche hinzufügen oder entfernen**.
2. Unten bei **„Bereiche manuell hinzufügen“** diese vier Zeilen einfügen → **Zur Tabelle hinzufügen** → **Aktualisieren** → **Speichern**:
   ```
   https://www.googleapis.com/auth/googlehealth.sleep.readonly
   https://www.googleapis.com/auth/googlehealth.health_metrics_and_measurements.readonly
   https://www.googleapis.com/auth/googlehealth.activity_and_fitness.readonly
   https://www.googleapis.com/auth/googlehealth.settings.readonly
   ```
   | Scope | Wofür |
   |---|---|
   | `sleep.readonly` | Schlaf mit Phasen |
   | `health_metrics_and_measurements.readonly` | Ruhepuls, HRV, Herzfrequenz |
   | `activity_and_fitness.readonly` | Schritte, Kalorien, Trainings |
   | `settings.readonly` | Gerät (Akku, letzte Synchronisation) |

   Alle sind **nur lesend** – dirks3n kann in deinem Google-Konto nichts verändern.

### A6. Zugangsdaten (Client) erstellen
1. Google Auth Platform → **Clients** → **+ Client erstellen**.
2. Anwendungstyp: **Webanwendung**. Name: `dirks3n`.
3. **Autorisierte Weiterleitungs-URIs** → **+ URI hinzufügen**:
   ```
   https://dirks3n.munzner.net/google-callback.php
   ```
   (Genau so – mit `https`, ohne Schrägstrich am Ende.)
4. **Erstellen**. Jetzt siehst du **Client-ID** (endet auf `.apps.googleusercontent.com`) und
   **Clientschlüssel** (beginnt mit `GOCSPX-`). **Beide kopieren** – am besten zusätzlich die
   JSON-Datei herunterladen und im Passwort-Manager ablegen. Der Schlüssel wird evtl. nur einmal angezeigt.

---

## Was bedeuten die „Restricted“-Scopes für dich?

Google stuft **alle** Gesundheits-Scopes als **„restricted“ (eingeschränkt)** ein – weil es um sehr
persönliche Daten geht. Für eine **öffentliche** App heißt das: Google prüft die App, und jedes Jahr
ist ein kostenpflichtiges Sicherheitsgutachten (CASA) fällig.

**Für eine App nur für dich gilt das nicht:**

| Variante | Prüfung durch Google? | Warnseite beim Verbinden? | Verbindung läuft ab? |
|---|---|---|---|
| **Personal** (falls angeboten) | nein | nein | nein |
| **Extern + Testing**, du als Testnutzer | nein | ja („App nicht überprüft“) – ist ok, es ist deine eigene App | **nach 7 Tagen** |
| Extern + „Veröffentlicht“ | **ja, Pflicht** (Monate, Gutachten) | – | – |

**Bei „Extern + Testing“:** Google beendet die Erlaubnis automatisch 7 Tage nach dem Verbinden.
dirks3n erkennt das, zeigt auf „Heute“ einen gelben Hinweis **„Neu verbinden“** und nutzt bis dahin die
Apple-Health-Daten. Einmal tippen, bei Google bestätigen – fertig, nichts geht verloren.

---

## Teil B – dirks3n vorbereiten

### B1. Datenbank erweitern
phpMyAdmin → deine Datenbank → Reiter **SQL** → Inhalt von **`private/sql/update_002_google.sql`**
einfügen → **OK**. (Legt 4 neue Tabellen an und ergänzt die Trainings um den Ø-Puls.)

> Neuinstallation? Dann ist alles schon in `schema.sql` enthalten – diesen Schritt überspringen.

### B2. Konfiguration ergänzen
In **`private/config.php`** vor der letzten Zeile `];` einfügen (Vorlage: `config.sample.php`):

```php
    'encryption_key' => 'HIER-64-ZEICHEN',
    'google' => [
        'client_id'     => 'HIER-CLIENT-ID.apps.googleusercontent.com',
        'client_secret' => 'GOCSPX-HIER',
        'redirect_uri'  => 'https://dirks3n.munzner.net/google-callback.php',
    ],
    'google_backfill_days' => 90,
```

- **encryption_key** erzeugen: Mac-Terminal → `openssl rand -hex 32` → die 64 Zeichen einsetzen.
  (Windows: Passwort-Manager, 64 Zeichen nur aus `0-9` und `a-f`.)
  ⚠️ Diesen Schlüssel **nicht mehr ändern** – sonst kann dirks3n die gespeicherten Tokens nicht
  mehr lesen und du musst neu verbinden.

### B3. Dateien hochladen
Per FTP alle geänderten Dateien hochladen und überschreiben – **außer** `private/config.php`
(die hast du ja gerade selbst bearbeitet; die lädst du einzeln hoch).
Neu sind u. a.: `public/google-connect.php`, `public/google-callback.php`,
`private/src/GoogleAuth.php`, `GoogleHealth.php`, `Crypto.php`, `public/assets/fonts/`.

---

## Teil C – Verbinden

1. dirks3n öffnen → **Profil** → Karte **Fitbit** → **Mit Google verbinden**.
2. Google-Konto auswählen (das mit deiner Charge 6).
3. Bei „Extern + Testing“ erscheint **„Google hat diese App nicht überprüft“**:
   → **Erweitert** → **Weiter zu dirks3n (unsicher)**. Das ist in Ordnung: Es ist deine eigene App.
4. **Alle Häkchen setzen** (Schlaf, Gesundheitswerte, Aktivität, Einstellungen) → **Weiter**.
5. Es erscheint **„Fitbit verbunden“**. Die letzten Tage sind sofort da;
   die übrigen bis 90 Tage lädt der Cronjob in den nächsten 1–3 Stunden nach
   (Fortschritt unter Profil → Fitbit → „Verlauf“).

> Öffnet sich der Google-Login auf dem iPhone in einem eigenen Fenster: nach „Fitbit verbunden“
> das Fenster schließen und dirks3n wieder öffnen.

---

## Wie es danach läuft

- **Stündlich** (dein bestehender Cronjob) holt dirks3n die letzten 3 Tage neu und lädt fehlende ältere Tage nach.
- **Fitbit hat Vorrang** vor Apple Health. Apple Health (Kurzbefehl) springt nur ein, wenn Fitbit für
  einen Tag nichts liefert – und liefert weiterhin z. B. dein Gewicht.
- **Morgens:** Sobald Fitbit deinen Schlaf hat, erstellt dirks3n die Trainingsentscheidung.
  Die Charge 6 überträgt über die Fitbit-/Google-Health-App auf dem iPhone – **einmal die App öffnen**
  beschleunigt das. Unter Profil → Fitbit siehst du „Uhr → App“ (letzte Übertragung der Uhr) und
  „App → dirks3n“ (letzter Abruf).
- **Abgelaufene Tokens:** Das kurzlebige Zugriffstoken (1 Stunde) erneuert dirks3n automatisch.
  Nur wenn Google die Erlaubnis ganz beendet (7-Tage-Regel oder du widerrufst sie), erscheint „Neu verbinden“.
- **Trennen:** Profil → Fitbit → Trennen. Widerruft den Zugriff auch bei Google. Geladene Daten bleiben.

---

## Was liefert die Charge 6?

| Datenart | Charge 6 | Hinweis |
|---|---|---|
| Schlaf mit Phasen (Tief/Leicht/REM/Wach) | ✅ | Nickerchen werden ignoriert |
| Ruhepuls (täglich) | ✅ | |
| HRV (täglich, RMSSD) | ✅ | **Nur nachts gemessen** – ohne ca. 3 h Schlaf kein Wert für den Tag |
| Herzfrequenz | ✅ | gespeichert als 5-Minuten-Werte (Ø, min, max) |
| Schritte | ✅ | |
| Trainings (Art, Dauer, kcal, Ø-Puls, Distanz) | ✅ | auch automatisch erkannte (SmartTrack) |
| Kalorien (aktiv + Grundumsatz) | ✅ | |
| Gewicht / Körperfett | ❌ | misst die Uhr nicht → weiter über Kurzbefehl/Waage |
| Stockwerke | ❌ | Charge 6 hat keinen Höhenmesser (nicht genutzt) |

Unter Profil → Fitbit zeigt dirks3n das tatsächlich gemeldete Gerät, den Akkustand und die letzte Synchronisation.

---

## Probleme?

| Meldung | Lösung |
|---|---|
| `redirect_uri_mismatch` | Die URI in A6 muss **exakt** `https://dirks3n.munzner.net/google-callback.php` sein (auch in config.php). |
| `access_denied` / „Zugriff abgelehnt“ | Du bist nicht als Testnutzer eingetragen (A4) oder hast „Abbrechen“ gewählt. |
| „Nicht freigegeben: sleep.readonly …“ | Beim Verbinden fehlte ein Häkchen → Neu verbinden, alle Häkchen setzen. |
| „Google Health Fehler 403“ | Google Health API nicht aktiviert (A2) oder Scopes fehlen (A5). |
| „Abruflimit erreicht“ (429) | Kein Problem – der nächste Cronjob macht weiter. |
| „Google hat keinen Refresh-Token geschickt“ | <https://myaccount.google.com/permissions> → dirks3n entfernen → neu verbinden. |
| Daten von heute fehlen | Fitbit-App auf dem iPhone öffnen (Uhr synchronisiert), dann Profil → Fitbit → **Abrufen**. |
| Hinweis „Datenbank-Update 002 fehlt“ | Teil B1 ausführen. |

Fehlerdetails stehen in `private/logs/app.log`.
