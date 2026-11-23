<?php
/**
 * Cron-Aufgaben. Wird über public/cron.php (Web-Cronjob von ALL-INKL) aufgerufen
 * oder direkt per Kommandozeile:  php private/cron/run.php hourly
 */
declare(strict_types=1);

if (PHP_SAPI === 'cli' && !function_exists('cfg')) {
    require dirname(__DIR__) . '/src/bootstrap.php';
}

function d3_run_cron(string $job): string
{
    @set_time_limit(150);
    $log = [];
    $hour = (int)now()->format('G');
    $day = today();

    // 1) Kalender alle ~55 Minuten aktualisieren
    if (in_array($job, ['hourly', 'calendar'], true)) {
        $last = Db::value("SELECT MAX(received_at) FROM d3_sync_log WHERE kind = 'calendar'");
        if ($job === 'calendar' || !$last || strtotime((string)$last) < time() - 55 * 60) {
            try {
                $r = Ics::syncAll();
                $log[] = 'Kalender: ' . $r['message'];
            } catch (Throwable $e) {
                $log[] = 'Kalender-Fehler: ' . $e->getMessage();
            }
        } else {
            $log[] = 'Kalender: noch aktuell';
        }
    }

    // 1b) Fitbit-Daten über Google Health holen (inkl. Nachladen früherer Tage)
    if (in_array($job, ['hourly', 'google'], true) && GoogleAuth::configured() && Aggregator::hasTable('d3_oauth_tokens')) {
        $st = GoogleAuth::status();
        if ($st && $st['status'] === 'connected') {
            try {
                $limit = (int)ini_get('max_execution_time');
                $budget = $limit > 0 ? max(10, min(40, $limit - 20)) : 40; // genug Zeit für den Rest des Cronjobs lassen
                $r = GoogleHealth::sync($budget);
                $log[] = 'Fitbit: ' . $r['message'] . " ({$r['days']} Tage, {$r['requests']} Abrufe, Verlauf {$r['backfill']} %)";
            } catch (Throwable $e) {
                $log[] = 'Fitbit-Fehler: ' . $e->getMessage();
            }
        } elseif ($st && $st['status'] === 'reauth') {
            $log[] = 'Fitbit: Google-Verbindung abgelaufen – bitte in der App neu verbinden';
        } else {
            $log[] = 'Fitbit: nicht verbunden';
        }
    }

    // 2) Tagesentscheidung – falls noch keine da ist
    if (in_array($job, ['hourly', 'decision'], true)) {
        if (Decision::get($day)) {
            $log[] = 'Entscheidung: existiert bereits';
        } else {
            $hasSleep = (Aggregator::dayValues($day)['sleep_min'] ?? 0) > 0;
            $deadline = (int)cfg('decision_deadline_hour', 10);
            if ($job === 'decision' || ($hasSleep && $hour >= 4) || $hour >= $deadline) {
                $d = Decision::make($day);
                $log[] = 'Entscheidung erstellt (' . $d['engine'] . '): ' . $d['kind'];
            } else {
                $log[] = "Entscheidung: warte auf Schlafdaten (spätestens $deadline Uhr)";
            }
        }
    }

    // 3) Aufräumen (einmal pro Nacht um 3 Uhr)
    if ($job === 'cleanup' || ($job === 'hourly' && $hour === 3)) {
        $lifetime = (int)cfg('session_days', 30) * 86400;
        $removed = 0;
        foreach (glob(D3_PRIVATE . '/sessions/sess_*') ?: [] as $f) {
            if (filemtime($f) < time() - $lifetime) {
                @unlink($f);
                $removed++;
            }
        }
        Db::run('DELETE FROM d3_login_attempts WHERE attempted_at < ?', [now()->modify('-1 day')->format('Y-m-d H:i:s')]);
        Db::run('DELETE FROM d3_sync_log WHERE received_at < ?', [now()->modify('-90 days')->format('Y-m-d H:i:s')]);
        if (Aggregator::hasTable('d3_oauth_states')) {
            Db::run('DELETE FROM d3_oauth_states WHERE created_at < ?', [now()->modify('-1 hour')->format('Y-m-d H:i:s')]);
        }
        if (Aggregator::hasTable('d3_heart_rate')) {
            // 5-Minuten-Puls nach 400 Tagen löschen (Tageswerte bleiben erhalten)
            Db::run('DELETE FROM d3_heart_rate WHERE ts < ?', [now()->modify('-400 days')->format('Y-m-d H:i:s')]);
        }
        foreach (['app.log', 'php-error.log'] as $lf) {
            $p = D3_PRIVATE . '/logs/' . $lf;
            if (is_file($p) && filesize($p) > 2 * 1024 * 1024) {
                @rename($p, $p . '.old');
            }
        }
        $log[] = "Aufräumen: $removed alte Sitzungen entfernt";
    }

    Db::run('INSERT INTO d3_sync_log (kind, items, info) VALUES (?,?,?)', ['cron', 0, mb_substr(implode(' | ', $log), 0, 500)]);
    return implode("\n", $log) . "\n";
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    echo d3_run_cron($argv[1] ?? 'hourly');
}
