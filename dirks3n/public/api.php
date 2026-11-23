<?php
/**
 * dirks3n – API für die App (nur nach Login).
 * Aufruf: api.php?a=<aktion>
 */
declare(strict_types=1);

require dirname(__DIR__) . '/private/src/bootstrap.php';

Http::requireHttps();
Http::securityHeaders();

$action = (string)($_GET['a'] ?? '');
$method = Http::method();

try {
    // ---------------------------------------------------------------- ohne Login
    if ($action === 'session') {
        $uid = Auth::userId();
        Http::json(['loggedIn' => $uid !== null, 'csrf' => Auth::csrfToken(), 'hasUser' => Auth::hasUser()]);
    }
    if ($action === 'login' && $method === 'POST') {
        $b = Http::body();
        $err = Auth::login(trim((string)($b['username'] ?? '')), (string)($b['password'] ?? ''));
        if ($err) {
            Http::error($err, 401);
        }
        Http::json(['ok' => true, 'csrf' => Auth::csrfToken()]);
    }

    // ---------------------------------------------------------------- ab hier Login nötig
    Auth::requireLogin();
    if ($method !== 'GET') {
        Auth::requireCsrf();
    }

    switch ($action) {
        case 'logout':
            Auth::logout();
            Http::json(['ok' => true]);

        case 'today':
            $day = validDay($_GET['day'] ?? today());
            Http::json(todayPayload($day));

        case 'decide':
            if ($method !== 'POST') Http::error('POST erwartet', 405);
            $b = Http::body();
            $day = validDay($b['day'] ?? today());
            $existing = Decision::get($day);
            $max = (int)cfg('max_regenerations_per_day', 3);
            if ($existing && $existing['revisions'] >= $max) {
                Http::error("Heute schon $max× neu entschieden – Limit erreicht (spart KI-Kosten).", 429);
            }
            Decision::make($day, true, trim((string)($b['note'] ?? '')));
            Http::json(todayPayload($day));

        case 'stats':
            $range = max(7, min(365, (int)($_GET['range'] ?? 30)));
            $end = validDay($_GET['end'] ?? today());
            Http::json(statsPayload($end, $range));

        case 'settings':
            if ($method === 'POST') {
                $b = Http::body();
                $profile = Settings::profile();
                foreach (Settings::DEFAULT_PROFILE as $k => $def) {
                    if (array_key_exists($k, (array)($b['profile'] ?? []))) {
                        $v = $b['profile'][$k];
                        $profile[$k] = is_numeric($def) || in_array($k, ['birth_year', 'height_cm'], true)
                            ? ($v === '' || $v === null ? null : (float)$v)
                            : mb_substr(trim((string)$v), 0, 500);
                    }
                }
                Settings::set('profile', $profile);
                if (isset($b['calendar_urls'])) {
                    $urls = [];
                    foreach ((array)$b['calendar_urls'] as $u) {
                        $u = trim((string)$u);
                        if ($u !== '' && preg_match('#^(https|webcals?)://#i', $u)) {
                            $urls[] = mb_substr($u, 0, 1000);
                        }
                    }
                    Settings::set('calendar_urls', array_slice($urls, 0, 5));
                }
            }
            Http::json([
                'profile' => Settings::profile(),
                'calendar_urls' => Settings::calendarUrls(),
                'ingest_url' => rtrim((string)cfg('base_url'), '/') . '/ingest.php',
                'ingest_token' => (string)cfg('ingest_token'),
                'ai' => aiInfo(),
                'google' => googleInfo(),
                'server' => [
                    'php' => PHP_VERSION,
                    'max_execution_time' => (int)ini_get('max_execution_time'),
                    'memory_limit' => (string)ini_get('memory_limit'),
                    'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
                    'post_max_size' => (string)ini_get('post_max_size'),
                    'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                ],
            ]);

        case 'google_sync':
            if ($method !== 'POST') Http::error('POST erwartet', 405);
            if (!GoogleAuth::configured() || !GoogleAuth::isConnected()) Http::error('Fitbit ist nicht verbunden', 400);
            $r = GoogleHealth::sync(25);
            // Neue Schlafdaten da und noch keine Entscheidung? Dann jetzt entscheiden.
            if (!$r['reauth'] && Decision::get(today()) === null && (int)now()->format('G') >= 4
                && (Aggregator::dayValues(today())['sleep_min'] ?? 0) > 0) {
                Decision::make(today());
                $r['message'] .= ' · Trainingsentscheidung erstellt';
            }
            Http::json($r + ['google' => GoogleHealth::statusInfo()]);

        case 'google_disconnect':
            if ($method !== 'POST') Http::error('POST erwartet', 405);
            if (GoogleAuth::configured()) GoogleAuth::disconnect();
            Http::json(['ok' => true, 'google' => GoogleHealth::statusInfo()]);

        case 'calendar_sync':
            if ($method !== 'POST') Http::error('POST erwartet', 405);
            Http::json(Ics::syncAll());

        default:
            Http::error('Unbekannte Aktion', 404);
    }
} catch (Throwable $e) {
    app_log('API-Fehler (' . $action . '): ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    Http::error('Serverfehler – Details stehen in private/logs/app.log', 500);
}

// ---------------------------------------------------------------------- Hilfsfunktionen

function validDay($d): string
{
    $d = (string)$d;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d) {
        Http::error('Ungültiges Datum');
    }
    return $d;
}

function aiInfo(): array
{
    return [
        'configured' => Claude::configured(),
        'spent_usd' => round(Claude::monthSpendUsd(), 3),
        'budget_usd' => Claude::budgetUsd(),
        'decision_model' => Claude::model('decision'),
    ];
}

function googleInfo(): array
{
    if (!Aggregator::hasTable('d3_oauth_tokens')) {
        return ['configured' => GoogleAuth::configured(), 'connected' => false, 'reauth' => false, 'needs_update' => true];
    }
    return GoogleHealth::statusInfo() + ['needs_update' => false];
}

/** Herzfrequenz eines Tages in 5-Minuten-Werten: [[HH:MM, Ø, min, max], …] */
function hrIntraday(string $day): array
{
    if (!Aggregator::hasTable('d3_heart_rate')) {
        return [];
    }
    $next = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    return array_map(fn($r) => [substr($r['ts'], 11, 5), (float)$r['bpm_avg'], (float)$r['bpm_min'], (float)$r['bpm_max']],
        Db::all('SELECT ts, bpm_avg, bpm_min, bpm_max FROM d3_heart_rate WHERE ts >= ? AND ts < ? ORDER BY ts', ["$day 00:00:00", "$next 00:00:00"]));
}

function todayPayload(string $day): array
{
    $readiness = Readiness::forDay($day);
    $events = Ics::eventsForDay($day);
    $lastHealth = Db::one("SELECT received_at, items, info FROM d3_sync_log WHERE kind = 'health' ORDER BY id DESC LIMIT 1");
    $lastCal = Db::one("SELECT received_at, items, info FROM d3_sync_log WHERE kind = 'calendar' ORDER BY id DESC LIMIT 1");
    $values = Aggregator::dayValues($day);
    $yesterday = Aggregator::dayValues((new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d'));
    $workouts = Aggregator::dedupedWorkouts($day . ' 00:00:00', (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d 00:00:00'));
    return [
        'day' => $day,
        'is_today' => $day === today(),
        'decision' => Decision::get($day),
        'readiness' => $readiness,
        'values' => $values,
        'yesterday' => $yesterday,
        'workouts' => array_map(fn($w) => [
            'activity' => $w['activity'], 'category' => $w['category'], 'start' => substr($w['start_at'], 11, 5),
            'duration_min' => (float)$w['duration_min'], 'energy_kcal' => $w['energy_kcal'] !== null ? (float)$w['energy_kcal'] : null,
            'avg_hr' => isset($w['avg_hr']) && $w['avg_hr'] !== null ? round((float)$w['avg_hr']) : null,
        ], $workouts),
        'events' => array_map(fn($e) => [
            'title' => $e['title'], 'location' => $e['location'], 'all_day' => (bool)$e['all_day'],
            'start' => substr($e['start_at'], 11, 5), 'end' => substr($e['end_at'], 11, 5),
        ], $events),
        'sync' => ['health' => $lastHealth, 'calendar' => $lastCal, 'google' => Db::one("SELECT received_at, info FROM d3_sync_log WHERE kind = 'google' ORDER BY id DESC LIMIT 1")],
        'google' => googleInfo(),
        'hr_intraday' => hrIntraday($day),
        'spark' => sparkSeries($day),
        'ai' => aiInfo(),
        'regenerations_left' => max(0, (int)cfg('max_regenerations_per_day', 3) - (int)(Decision::get($day)['revisions'] ?? 0)),
    ];
}

/** Mini-Verläufe (7 Tage) für die Kacheln */
function sparkSeries(string $day): array
{
    $from = (new DateTimeImmutable($day))->modify('-6 days')->format('Y-m-d');
    $metrics = ['sleep_min', 'hrv', 'resting_hr', 'steps', 'active_kcal', 'weight', 'hr_avg'];
    $s = Aggregator::series($from, $day, $metrics);
    $out = [];
    foreach ($metrics as $m) {
        $vals = [];
        for ($i = 0; $i < 7; $i++) {
            $d = (new DateTimeImmutable($from))->modify("+$i days")->format('Y-m-d');
            $vals[] = $s[$m][$d] ?? null;
        }
        $out[$m] = $vals;
    }
    return $out;
}

function statsPayload(string $end, int $range): array
{
    $endD = new DateTimeImmutable($end);
    $start = $endD->modify('-' . ($range - 1) . ' days')->format('Y-m-d');
    $prevStart = $endD->modify('-' . (2 * $range - 1) . ' days')->format('Y-m-d');
    $prevEnd = $endD->modify("-$range days")->format('Y-m-d');
    $metrics = ['sleep_min', 'sleep_deep_min', 'sleep_rem_min', 'sleep_core_min', 'sleep_bedtime', 'hrv', 'resting_hr', 'steps',
        'weight', 'body_fat', 'active_kcal', 'basal_kcal', 'training_load', 'training_min', 'vo2max', 'hr_avg', 'hr_min', 'hr_max'];
    $cur = Aggregator::series($start, $end, $metrics);
    $prev = Aggregator::series($prevStart, $prevEnd, $metrics);

    $days = [];
    for ($d = new DateTimeImmutable($start); $d <= $endD; $d = $d->modify('+1 day')) {
        $days[] = $d->format('Y-m-d');
    }
    $series = [];
    $summary = [];
    foreach ($metrics as $m) {
        $series[$m] = array_map(fn($day) => $cur[$m][$day] ?? null, $days);
        $avg = $cur[$m] ? array_sum($cur[$m]) / count($cur[$m]) : null;
        $pavg = $prev[$m] ? array_sum($prev[$m]) / count($prev[$m]) : null;
        $summary[$m] = [
            'avg' => $avg !== null ? round($avg, 1) : null,
            'prev_avg' => $pavg !== null ? round($pavg, 1) : null,
            'count' => count($cur[$m]),
        ];
    }
    $readiness = [];
    foreach (Db::all('SELECT day, readiness FROM d3_decisions WHERE day >= ? AND day <= ?', [$start, $end]) as $r) {
        $readiness[$r['day']] = $r['readiness'] !== null ? (int)$r['readiness'] : null;
    }
    $series['readiness'] = array_map(fn($day) => $readiness[$day] ?? null, $days);

    $workouts = Aggregator::dedupedWorkouts($start . ' 00:00:00', $endD->modify('+1 day')->format('Y-m-d 00:00:00'));
    return [
        'range' => $range,
        'start' => $start,
        'end' => $end,
        'days' => $days,
        'series' => $series,
        'summary' => $summary,
        'workouts' => array_map(fn($w) => [
            'date' => substr($w['start_at'], 0, 10), 'start' => substr($w['start_at'], 11, 5), 'activity' => $w['activity'],
            'category' => $w['category'], 'duration_min' => round((float)$w['duration_min']), 'load' => (float)$w['load_score'],
            'avg_hr' => isset($w['avg_hr']) && $w['avg_hr'] !== null ? round((float)$w['avg_hr']) : null, 'source' => $w['source'],
            'energy_kcal' => $w['energy_kcal'] !== null ? round((float)$w['energy_kcal']) : null,
        ], array_reverse($workouts)),
    ];
}
