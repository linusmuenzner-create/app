# dirks3n – persönliche KI-Fitness-App

PWA (HTML/CSS/JS) + PHP 8 + MySQL – läuft auf Shared Hosting (ALL-INKL), ohne Build-Schritt.

**Stand: MVP + Fitbit** – Login, Fitbit Charge 6 über die Google Health API (OAuth, stündlicher Abruf,
90 Tage Verlauf), Apple Health (Kurzbefehl) als Ersatzquelle, Dashboard mit Diagrammen,
tägliche Trainingsentscheidung (KI + Regeln), Kalender-Import (ICS), Cron.
Als Nächstes: Workout-Tracking, Wochen-Chat, Ernährung, KI-Coach, Onboarding mit Fotos.

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
│   ├── setup.php           Ersteinrichtung (sperrt sich danach selbst)
│   ├── sw.js, manifest.webmanifest
│   └── assets/             CSS, JS, Icons, Chart.js (lokal)
├── private/                ← NICHT öffentlich
│   ├── config.sample.php   Vorlage → als config.php kopieren
│   ├── sql/schema.sql      Datenbank-Tabellen (Neuinstallation)
│   ├── sql/update_002_google.sql  Update für bestehende Installationen
│   ├── src/                PHP-Logik
│   │   ├── HealthIngest.php  Daten einlesen (Kurzbefehl-Text oder JSON)
│   │   ├── Aggregator.php    Tageswerte berechnen (ohne Doppelzählung)
│   │   ├── Readiness.php     Bereitschafts-Score (ohne KI)
│   │   ├── Decision.php      Trainingsentscheidung (KI, Fallback Regeln)
│   │   ├── Claude.php        Claude-API per cURL + Kostenbremse
│   │   ├── GoogleAuth.php    Google-Login (OAuth 2.0 + PKCE), Tokens verschlüsselt
│   │   ├── GoogleHealth.php  Abruf Fitbit-Daten (v4), Nachladen, Speicherung
│   │   ├── Crypto.php        Verschlüsselung der Tokens
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

## Update von der ersten Version
1. `private/sql/update_002_google.sql` in phpMyAdmin ausführen.
2. Alle Dateien außer `private/config.php` per FTP überschreiben.
3. Google einrichten: docs/GOOGLE-HEALTH.md.
