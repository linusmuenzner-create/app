# dirks3n – persönliche KI-Fitness-App

PWA (HTML/CSS/JS) + PHP 8 + MySQL – läuft auf Shared Hosting (ALL-INKL), ohne Build-Schritt.

**Stand:** Login, Fitbit Charge 6 über die Google Health API (OAuth, stündlicher Abruf, 90 Tage Verlauf),
Apple Health (Kurzbefehl) als Ersatzquelle, Dashboard mit Diagrammen, tägliche Trainingsentscheidung
(KI + Regeln), **Workout-Tracking** (Übungsbibliothek mit Bildern, exakte Vorgaben inkl. Gewicht,
Pausentimer, Fortschritt pro Übung), Kalender-Import (ICS), Cron.
Als Nächstes: Wochen-Chat, Ernährung, KI-Coach, Onboarding mit Fotos.

## Ordner

```
dirks3n/
├── public/                 ← Webroot der Subdomain (nur das ist öffentlich)
│   ├── index.html          App-Hülle (PWA)
│   ├── api.php             API für die App (Login nötig)
│   ├── ingest.php          Datenempfang vom iPhone-Kurzbefehl (Token)
│   ├── cron.php            Stündlicher Cronjob (Schlüssel)
│   ├── google-connect.php  „Mit Google verbinden“ (Start)
│   ├── google-callback.php Rückkehr von Google (Redirect-URL)
│   ├── exercise-img.php    Übungsbilder (einmal von GitHub geladen, dann lokal)
│   ├── setup.php           Ersteinrichtung (sperrt sich danach selbst)
│   ├── sw.js, manifest.webmanifest
│   └── assets/             CSS, JS, Icons, Chart.js (lokal)
├── private/                ← NICHT öffentlich
│   ├── config.sample.php   Vorlage → als config.php kopieren
│   ├── sql/schema.sql      Datenbank-Tabellen (Neuinstallation)
│   ├── sql/update_002_google.sql   Update: Fitbit/Google
│   ├── sql/update_003_training.sql Update: Workout-Tracking
│   ├── sql/update_004_goals.sql    Update: Ziel-Chat
│   ├── data/exercises.json  Übungsbibliothek (Free Exercise DB, gemeinfrei)
│   ├── src/                PHP-Logik
│   │   ├── HealthIngest.php  Daten einlesen (Kurzbefehl-Text oder JSON)
│   │   ├── Aggregator.php    Tageswerte berechnen (ohne Doppelzählung)
│   │   ├── Readiness.php     Bereitschafts-Score (ohne KI)
│   │   ├── Decision.php      Trainingsentscheidung (KI, Fallback Regeln)
│   │   ├── Claude.php        Claude-API per cURL + Kostenbremse
│   │   ├── GoogleAuth.php    Google-Login (OAuth 2.0 + PKCE), Tokens verschlüsselt
│   │   ├── GoogleHealth.php  Abruf Fitbit-Daten (v4), Nachladen, Speicherung
│   │   ├── Crypto.php        Verschlüsselung der Tokens
│   │   ├── Exercises.php     Übungsbibliothek, deutsche Kernauswahl, Bild-Cache
│   │   ├── Training.php      Trainingseinheiten, Sätze, Gewichtssteigerung, Verlauf
│   │   ├── Goals.php         Ziel-Chat (Nachfragen, strukturierte Ziele, Bestätigung)
│   │   └── Ics.php           Kalender (inkl. Serientermine)
│   ├── cron/run.php
│   └── logs/ sessions/ uploads/ cache/
└── docs/
    ├── ANLEITUNG.md        Installation auf ALL-INKL
    ├── GOOGLE-HEALTH.md    Fitbit Charge 6 über Google Health anbinden
    ├── KURZBEFEHL.md       iPhone-Kurzbefehl für Apple Health
    └── TESTEN.md           Test-Checkliste
```

## Datenfluss

- **Fitbit Charge 6** → Google Health API → `cron.php` (stündlich) → Rohdaten (Quelle `google`, hat Vorrang)
- **Apple Health** (Gewicht, Ersatz, ab Dez. Garmin) → Kurzbefehl → `ingest.php` → Rohdaten
- Rohdaten → Tageswerte (ohne Doppelzählung) → Bereitschaft → **1 KI-Aufruf/Tag** → Entscheidung

## Updates einspielen
1. Fehlende Datenbank-Updates in phpMyAdmin ausführen (jeweils nur einmal, in dieser Reihenfolge):
   `private/sql/update_002_google.sql` (Fitbit) → `private/sql/update_003_training.sql` (Training)
   → `private/sql/update_004_goals.sql` (Ziel-Chat).
2. Alle Dateien außer `private/config.php` per FTP überschreiben (inkl. neuer Ordner `private/data/`).
3. Optional in `config.php` unter `models` ergänzen: `'translate' => 'claude-haiku-5-5',`
   (ohne Eintrag wird automatisch Haiku genutzt).
4. Google einrichten: docs/GOOGLE-HEALTH.md.

## Workout-Tracking – wie die Vorgaben entstehen
- Der Coach (oder die Regeln) wählt die Übungen aus der deutschen Kernauswahl (52 Übungen) inkl. Sätze × Wiederholungen.
- Das **Gewicht** rechnet dirks3n selbst aus deinem Verlauf aus (keine KI-Kosten):
  alle Sätze am oberen Ende geschafft → +2,5 kg (Kurzhantel +2 kg, Isolation +1 kg);
  mehr als die Hälfte unter dem Zielbereich → ca. 5 % leichter; sonst gleich.
- Beim ersten Mal trägst du dein Startgewicht selbst ein.
- „Gerne / Lieber nicht“ bei einer Übung beeinflusst die Auswahl des Coachs.
