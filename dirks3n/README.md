# dirks3n – persönliche KI-Fitness-App

PWA (HTML/CSS/JS) + PHP 8 + MySQL – läuft auf Shared Hosting (ALL-INKL), ohne Build-Schritt.

**Stand: MVP** – Login, Datenempfang aus Apple Health (Kurzbefehl), Dashboard mit Diagrammen,
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
│   ├── setup.php           Ersteinrichtung (sperrt sich danach selbst)
│   ├── sw.js, manifest.webmanifest
│   └── assets/             CSS, JS, Icons, Chart.js (lokal)
├── private/                ← NICHT öffentlich
│   ├── config.sample.php   Vorlage → als config.php kopieren
│   ├── sql/schema.sql      Datenbank-Tabellen
│   ├── src/                PHP-Logik
│   │   ├── HealthIngest.php  Daten einlesen (Kurzbefehl-Text oder JSON)
│   │   ├── Aggregator.php    Tageswerte berechnen (ohne Doppelzählung)
│   │   ├── Readiness.php     Bereitschafts-Score (ohne KI)
│   │   ├── Decision.php      Trainingsentscheidung (KI, Fallback Regeln)
│   │   ├── Claude.php        Claude-API per cURL + Kostenbremse
│   │   └── Ics.php           Kalender (inkl. Serientermine)
│   ├── cron/run.php
│   └── logs/ sessions/ uploads/ cache/
└── docs/
    ├── ANLEITUNG.md        Installation auf ALL-INKL
    ├── KURZBEFEHL.md       iPhone-Kurzbefehl für Apple Health
    └── TESTEN.md           Test-Checkliste
```

## Datenfluss

Google Health / (ab Dez.) Garmin → **Apple Health** → Kurzbefehl (beim Wecker-Ausschalten) →
`ingest.php` → Rohdaten → Tageswerte → Bereitschaft → **1 KI-Aufruf/Tag** → Entscheidung.
