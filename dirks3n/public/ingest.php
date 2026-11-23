<?php
/**
 * dirks3n – Empfang der Gesundheitsdaten vom iPhone-Kurzbefehl.
 * Gesichert mit dem geheimen Token aus config.php (Header "Authorization: Bearer <token>").
 */
declare(strict_types=1);

require dirname(__DIR__) . '/private/src/bootstrap.php';

Http::requireHttps();
Http::securityHeaders();

if (Http::method() !== 'POST') {
    Http::error('Bitte per POST senden', 405);
}
if (!token_equals((string)cfg('ingest_token'), Http::bearerToken())) {
    usleep(500000);
    Http::error('Token falsch', 401);
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 8 * 1024 * 1024) {
    Http::error('Zu viele Daten auf einmal (max. 8 MB)', 413);
}

try {
    $result = (new HealthIngest())->handle($raw, $_SERVER['CONTENT_TYPE'] ?? '');
} catch (Throwable $e) {
    app_log('Ingest-Fehler: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    Http::error('Serverfehler beim Speichern', 500);
}

$day = today();
$needsDecision = Decision::get($day) === null && (int)now()->format('G') >= 4
    && (Aggregator::dayValues($day)['sleep_min'] ?? 0) > 0;

$msg = $result['saved'] . ' Werte gespeichert.';
if ($needsDecision) {
    $msg .= ' Trainingsentscheidung wird erstellt – öffne gleich dirks3n.';
}
if ($result['warnings']) {
    $msg .= ' Hinweise: ' . count($result['warnings']);
}

// Antwort für den Kurzbefehl (wird als Mitteilung angezeigt)
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'message' => $msg] + $result, JSON_UNESCAPED_UNICODE);

// Danach (ohne den Kurzbefehl warten zu lassen) die Tagesentscheidung erstellen
if ($needsDecision) {
    Http::finishEarly();
    try {
        Decision::make($day);
    } catch (Throwable $e) {
        app_log('Entscheidung nach Ingest fehlgeschlagen: ' . $e->getMessage());
    }
}
