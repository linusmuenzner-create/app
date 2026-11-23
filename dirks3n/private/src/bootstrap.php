<?php
/**
 * Lädt Konfiguration, Datenbank und Hilfsfunktionen.
 * Wird von jeder öffentlichen PHP-Datei als Erstes eingebunden.
 */
declare(strict_types=1);

define('D3_PRIVATE', dirname(__DIR__));

$configFile = D3_PRIVATE . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "config.php fehlt. Bitte private/config.sample.php nach private/config.php kopieren und ausfüllen.";
    exit;
}
$GLOBALS['D3_CONFIG'] = require $configFile;

date_default_timezone_set(cfg('timezone', 'Europe/Berlin'));
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', D3_PRIVATE . '/logs/php-error.log');
mb_internal_encoding('UTF-8');

foreach (['Db', 'Http', 'Auth', 'Settings', 'HealthIngest', 'Aggregator', 'Readiness', 'Claude', 'Decision', 'Ics', 'Crypto', 'GoogleAuth', 'GoogleHealth', 'Exercises', 'Training', 'Goals'] as $cls) {
    require_once __DIR__ . '/' . $cls . '.php';
}

/** Konfigurationswert lesen */
function cfg(string $key, $default = null)
{
    return $GLOBALS['D3_CONFIG'][$key] ?? $default;
}

/** Aktuelle Zeit als DateTimeImmutable in der App-Zeitzone */
function now(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

function today(): string
{
    return now()->format('Y-m-d');
}

/** Einfaches Protokoll in private/logs/app.log */
function app_log(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents(D3_PRIVATE . '/logs/app.log', $line, FILE_APPEND | LOCK_EX);
}

/** Zeitkonstanter Vergleich für Tokens */
function token_equals(string $expected, ?string $given): bool
{
    return $given !== null && $expected !== '' && hash_equals($expected, $given);
}
