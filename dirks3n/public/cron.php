<?php
/**
 * dirks3n – Zeitgesteuerte Aufgaben (für den Cronjob im ALL-INKL-KAS).
 * Aufruf (stündlich): https://dirks3n.munzner.net/cron.php?key=<cron_key>
 * Die eigentliche Logik steht in private/cron/run.php.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/private/src/bootstrap.php';

Http::securityHeaders();
if (!token_equals((string)cfg('cron_key'), isset($_GET['key']) ? (string)$_GET['key'] : null)) {
    Http::error('Falscher Schlüssel', 401);
}
header('Content-Type: text/plain; charset=utf-8');
require D3_PRIVATE . '/cron/run.php';
echo d3_run_cron((string)($_GET['job'] ?? 'hourly'));
