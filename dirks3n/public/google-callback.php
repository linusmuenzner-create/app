<?php
/**
 * dirks3n – Rückkehr von Google nach dem Login (Redirect-URL).
 * Prüft den Einmal-Code (state + PKCE), speichert die Tokens verschlüsselt
 * und lädt die ersten Daten.
 *
 * Hinweis: Hier wird bewusst nicht die Login-Sitzung verlangt, weil iOS den Google-Login
 * aus der Home-Bildschirm-App oft in einem separaten Browserfenster ohne Sitzung öffnet.
 * Geschützt ist der Vorgang durch den zufälligen, einmal gültigen "state" (10 Minuten),
 * den nur ein angemeldeter Nutzer über google-connect.php erzeugen kann.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/private/src/bootstrap.php';

Http::requireHttps();
Http::securityHeaders();
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'");

$ok = false;
$msg = '';
$state = (string)($_GET['state'] ?? '');
$code = (string)($_GET['code'] ?? '');
$error = (string)($_GET['error'] ?? '');

if ($error !== '') {
    $msg = $error === 'access_denied'
        ? 'Du hast den Zugriff abgelehnt oder nicht alle Häkchen gesetzt. Versuche es erneut und erlaube alle Datenarten.'
        : 'Google meldet: ' . $error;
} elseif (!preg_match('/^[0-9a-f]{64}$/', $state) || $code === '') {
    $msg = 'Ungültige Rückkehr von Google.';
} else {
    try {
        GoogleAuth::handleCallback($state, $code);
        $ok = true;
        try {
            $r = GoogleHealth::sync(20); // erste Daten sofort; der Rest folgt per Cronjob
            $msg = 'Verbunden! ' . ($r['ok'] ? 'Die ersten Daten sind da.' : $r['message']) . ' Ältere Tage (bis ' . (int)cfg('google_backfill_days', 90) . ' Tage zurück) lädt der Cronjob in den nächsten Stunden nach.';
        } catch (Throwable $e) {
            $msg = 'Verbunden! Die Daten werden beim nächsten Cronjob geladen.';
            app_log('Google-Erstabruf: ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        app_log('Google-Callback: ' . $e->getMessage());
        $msg = $e->getMessage();
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>dirks3n · Fitbit</title>
<style>
  body { margin:0; min-height:100vh; display:grid; place-items:center; background:#07080a; color:#f4f6f8; font:16px/1.5 -apple-system, system-ui, sans-serif; padding:24px; box-sizing:border-box; }
  main { max-width:420px; text-align:center; }
  .badge { width:64px; height:64px; border-radius:20px; display:grid; place-items:center; margin:0 auto 16px; font-size:30px; font-weight:700; }
  .ok { background:rgba(198,244,50,.14); color:#c6f432; } .err { background:rgba(255,107,107,.14); color:#ff6b6b; }
  h1 { font-size:22px; margin:0 0 8px; } p { color:#a3abb8; }
  a { display:inline-block; margin-top:16px; padding:13px 22px; border-radius:14px; background:#c6f432; color:#0b0d10; font-weight:700; text-decoration:none; }
</style>
</head>
<body><main>
  <div class="badge <?= $ok ? 'ok' : 'err' ?>"><?= $ok ? '✓' : '!' ?></div>
  <h1><?= $ok ? 'Fitbit verbunden' : 'Verbindung fehlgeschlagen' ?></h1>
  <p><?= h($msg) ?></p>
  <p style="font-size:14px">Falls sich dieses Fenster separat geöffnet hat: einfach schließen und dirks3n wieder öffnen.</p>
  <a href="./#/einstellungen">Zurück zu dirks3n</a>
</main></body>
</html>
