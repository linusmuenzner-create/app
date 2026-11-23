<?php
/**
 * dirks3n – Konfiguration
 *
 * 1. Diese Datei kopieren und als "config.php" im selben Ordner speichern.
 * 2. Alle Werte mit "ÄNDERN" ausfüllen.
 * 3. config.php liegt im Ordner "private" und ist von außen NICHT erreichbar.
 *    Lade sie niemals in den Ordner "public" hoch und committe sie nie in Git.
 */
return [
    // Datenbank (aus dem KAS: Datenbanken → Datenbank anlegen)
    'db' => [
        'host'     => 'localhost',
        'name'     => 'd0xxxxxx',          // ÄNDERN: Datenbankname
        'user'     => 'd0xxxxxx',          // ÄNDERN: Datenbank-Benutzer (bei ALL-INKL = Datenbankname)
        'password' => 'ÄNDERN',            // ÄNDERN: Datenbank-Passwort
    ],

    // Zeitzone für alle Datumsangaben
    'timezone' => 'Europe/Berlin',

    // Öffentliche Adresse der App (ohne Schrägstrich am Ende)
    'base_url' => 'https://dirks3n.munzner.net',

    // Einmal-Schlüssel für die Ersteinrichtung (setup.php). Lange Zufallszeichenkette.
    // Nach der Einrichtung ist setup.php automatisch gesperrt.
    'setup_key' => 'ÄNDERN-zufaellige-zeichen-1',

    // Geheimer Token für den iPhone-Kurzbefehl (Datenübertragung). Mind. 32 Zeichen.
    'ingest_token' => 'ÄNDERN-zufaellige-zeichen-2',

    // Geheimer Schlüssel für die Cronjobs (cron.php?key=...). Mind. 32 Zeichen.
    'cron_key' => 'ÄNDERN-zufaellige-zeichen-3',

    // Claude API (console.anthropic.com → API Keys). Leer lassen = KI aus, nur Regeln.
    'anthropic_api_key' => '',

    // Welches Modell wofür (Variante B: Mix)
    'models' => [
        'decision'      => 'claude-sonnet-5-5', // tägliche Trainingsentscheidung
        'coach'         => 'claude-sonnet-5-5', // KI-Coach (späteres Modul)
        'body_photo'    => 'claude-sonnet-5-5', // Körperfoto (späteres Modul)
        'food_photo'    => 'claude-haiku-5-5',  // Essensfoto (späteres Modul)
        'calendar_chat' => 'claude-haiku-5-5',  // Wochen-Chat (späteres Modul)
        'translate'     => 'claude-haiku-5-5',  // Übungsanleitungen einmalig übersetzen
    ],

    // Harte Kostengrenze pro Kalendermonat in US-Dollar. Danach nur noch Regeln.
    'ai_monthly_budget_usd' => 3.00,

    // Bis wann spätestens entschieden wird, falls morgens keine Daten kamen (Uhrzeit, 24h)
    'decision_deadline_hour' => 10,

    // Wie oft du die Tagesentscheidung pro Tag manuell neu anfordern darfst
    'max_regenerations_per_day' => 3,

    // Login: Sitzung bleibt so viele Tage gültig (für die Home-Bildschirm-App praktisch)
    'session_days' => 30,

    // ---------------------------------------------------------------------------
    // Fitbit Charge 6 über die Google Health API (Anleitung: docs/GOOGLE-HEALTH.md)
    // ---------------------------------------------------------------------------

    // Schlüssel zum Verschlüsseln der Google-Tokens in der Datenbank: 64 Hex-Zeichen.
    // Erzeugen (Mac-Terminal): openssl rand -hex 32
    // NICHT mehr ändern, sobald du verbunden bist (sonst musst du dich neu verbinden).
    'encryption_key' => '',

    // Aus der Google Cloud Console → Google Auth Platform → Clients
    'google' => [
        'client_id'     => '',   // endet auf .apps.googleusercontent.com
        'client_secret' => '',   // beginnt mit GOCSPX-
        'redirect_uri'  => 'https://dirks3n.munzner.net/google-callback.php',
    ],

    // Wie viele Tage beim ersten Verbinden nachgeladen werden
    'google_backfill_days' => 90,
];
