<?php
/**
 * dirks3n – Startet die Verbindung mit Google (Fitbit-Daten).
 * Nur für angemeldete Nutzer. Leitet zu Google weiter.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/private/src/bootstrap.php';

Http::requireHttps();
Http::securityHeaders();

if (Auth::userId() === null) {
    header('Location: ./');
    exit;
}
if (!GoogleAuth::configured()) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo 'Google ist noch nicht eingerichtet: google → client_id / client_secret und encryption_key in private/config.php ausfüllen (siehe docs/GOOGLE-HEALTH.md).';
    exit;
}
header('Location: ' . GoogleAuth::authorizationUrl());
