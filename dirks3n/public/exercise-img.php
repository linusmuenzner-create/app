<?php
/**
 * dirks3n – Übungsbilder (nur nach Login).
 * Beim ersten Aufruf wird das Bild einmalig aus der gemeinfreien Free Exercise DB (GitHub) geladen
 * und in private/cache/exercises/ gespeichert. Danach kommt es direkt von deinem Server.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/private/src/bootstrap.php';

if (Auth::userId() === null) {
    http_response_code(401);
    exit;
}
session_write_close(); // Bilder parallel laden, ohne auf die Sitzung zu warten

$id = (string)($_GET['id'] ?? '');
$n = (int)($_GET['n'] ?? 0);
if (!preg_match('/^[A-Za-z0-9_,()\-]{1,100}$/', $id) || $n < 0 || $n > 5) {
    http_response_code(400);
    exit;
}

$file = Exercises::imageCachePath($id, $n);
if (!is_file($file)) {
    // Nur Übungen aus der eigenen Bibliothek (kein beliebiges Nachladen fremder Adressen)
    $row = Exercises::get($id);
    if (!$row || $n >= (int)$row['image_count'] || !Exercises::cacheImage($id, $n)) {
        http_response_code(404);
        exit;
    }
}

header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=2592000, immutable');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file));
readfile($file);
