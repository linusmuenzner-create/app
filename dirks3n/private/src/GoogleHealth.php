<?php
declare(strict_types=1);

/**
 * Google Health API (v4) – Abruf der Fitbit-Daten (Charge 6) und Speicherung in dirks3n.
 *
 * Datenarten → Speicherort:
 *   sleep (Phasen)                 → d3_health_samples metric 'sleep'      (source 'google')
 *   daily-resting-heart-rate       → d3_health_samples metric 'resting_hr'
 *   daily-heart-rate-variability   → d3_health_samples metric 'hrv'        (RMSSD, ms)
 *   steps (Tagessumme)             → d3_health_samples metric 'steps'
 *   active-energy-burned (Tag)     → d3_health_samples metric 'active_kcal'
 *   total-calories − aktiv (Tag)   → d3_health_samples metric 'basal_kcal'
 *   exercise                       → d3_workouts                             (source 'google')
 *   heart-rate (5-Minuten-Fenster) → d3_heart_rate
 *
 * Danach berechnet der Aggregator die Tageswerte neu – Dashboard und Trainingsentscheidung
 * nutzen die Daten automatisch. Fitbit hat dabei Vorrang vor Apple Health.
 */
final class GoogleHealth
{
    private const API_BASE = 'https://health.googleapis.com/v4/';
    public const SOURCE = 'google';

    /** Abruf-Typen und wie viele Tage pro Anfrage geholt werden (API-Grenzen beachtet) */
    private const TYPES = [
        'sleep'      => 30,
        'resting_hr' => 90,
        'hrv'        => 90,
        'steps'      => 90,
        'active_kcal' => 90,
        'total_kcal' => 13,  // API-Grenze 14 Tage – 13, damit die Zeitumstellung (25-h-Tag) nicht darüber kommt
        'exercise'   => 30,
        'heart_rate' => 13,  // dto.
    ];

    private float $deadline;
    private array $days = [];
    private array $log = [];
    private int $requests = 0;

    // ------------------------------------------------------------------ HTTP

    public static function get(string $path, array $query = []): array
    {
        return self::request('GET', $path, $query);
    }

    public static function post(string $path, array $body): array
    {
        return self::request('POST', $path, [], $body);
    }

    private static function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $g = (array)cfg('google', []);
        $url = ($g['api_base'] ?? self::API_BASE) . ltrim($path, '/');
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $refreshed = false;
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $token = GoogleAuth::accessToken();
            $ch = curl_init($url);
            $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_HEADER         => true,
                CURLOPT_CUSTOMREQUEST  => $method,
            ];
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }
            $opts[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            if ($raw === false) {
                if ($attempt < 3) { sleep(1); continue; }
                throw new RuntimeException('Google Health nicht erreichbar: ' . curl_error($ch));
            }
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headersRaw = substr((string)$raw, 0, $hsize);
            $json = json_decode(substr((string)$raw, $hsize), true);

            if ($status === 200) {
                return is_array($json) ? $json : [];
            }
            if ($status === 401 && !$refreshed) {
                $refreshed = true; // Token evtl. gerade abgelaufen → einmal erneuern und wiederholen
                GoogleAuth::accessToken(true);
                continue;
            }
            if (($status === 429 || $status >= 500) && $attempt < 3) {
                $wait = preg_match('/^retry-after:\s*(\d+)/mi', $headersRaw, $m) ? (int)$m[1] : 2 * $attempt;
                sleep(min(5, max(1, $wait)));
                continue;
            }
            $msg = is_array($json) ? (string)($json['error']['message'] ?? '') : '';
            if ($status === 401) {
                GoogleAuth::markReauth("API verweigert Zugriff (401) $msg");
                throw new GoogleReauthNeeded('Google-Verbindung muss erneuert werden.');
            }
            if ($status === 429) {
                throw new RuntimeException('Google-Abruflimit erreicht – nächster Versuch beim nächsten Cronjob.');
            }
            throw new RuntimeException("Google Health Fehler $status: " . mb_substr($msg, 0, 200));
        }
        throw new RuntimeException('Google Health: zu viele Wiederholungen');
    }

    /** Alle Seiten einer Liste holen */
    private function listAll(string $dataType, string $filter, int $pageSize): array
    {
        $out = [];
        $token = '';
        for ($page = 0; $page < 40; $page++) {
            $q = ['filter' => $filter, 'pageSize' => $pageSize];
            if ($token !== '') {
                $q['pageToken'] = $token;
            }
            $this->requests++;
            $res = self::get("users/me/dataTypes/$dataType/dataPoints", $q);
            foreach ((array)($res['dataPoints'] ?? []) as $dp) {
                $out[] = $dp;
            }
            $token = (string)($res['nextPageToken'] ?? '');
            if ($token === '') {
                break;
            }
        }
        return $out;
    }

    private function dailyRollUp(string $dataType, string $from, string $to): array
    {
        $out = [];
        $token = '';
        for ($page = 0; $page < 10; $page++) {
            $body = ['range' => ['start' => ['date' => self::dateObj($from)], 'end' => ['date' => self::dateObj($to)]], 'windowSizeDays' => 1];
            if ($token !== '') {
                $body['pageToken'] = $token;
            }
            $this->requests++;
            $res = self::post("users/me/dataTypes/$dataType/dataPoints:dailyRollUp", $body);
            foreach ((array)($res['rollupDataPoints'] ?? []) as $p) {
                $out[] = $p;
            }
            $token = (string)($res['nextPageToken'] ?? '');
            if ($token === '') {
                break;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ Synchronisation

    /**
     * Abruf durchführen: zuerst die letzten 3 Tage (aktuell halten), dann ältere Tage nachladen,
     * bis das Zeitbudget aufgebraucht ist. Läuft beim nächsten Cronjob einfach weiter.
     */
    public static function sync(int $maxSeconds = 40): array
    {
        $self = new self();
        return $self->run($maxSeconds);
    }

    private function run(int $maxSeconds): array
    {
        $this->deadline = microtime(true) + $maxSeconds;
        $today = today();
        $incFrom = (new DateTimeImmutable($today))->modify('-2 days')->format('Y-m-d');
        $incTo = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $backfillDays = max(3, (int)cfg('google_backfill_days', 90));
        $target = (new DateTimeImmutable($today))->modify("-$backfillDays days")->format('Y-m-d');
        $errors = [];

        try {
            $this->refreshDevice();
        } catch (GoogleReauthNeeded $e) {
            return $this->finish(['ok' => false, 'reauth' => true, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            $errors[] = 'Gerät: ' . $e->getMessage();
        }

        // 1) Aktuelle Tage
        foreach (array_keys(self::TYPES) as $type) {
            if ($this->timeUp()) break;
            try {
                $this->fetch($type, $incFrom, $incTo);
                $state = Db::one('SELECT backfill_from FROM d3_google_sync WHERE data_type = ?', [$type]);
                Db::run(
                    'INSERT INTO d3_google_sync (data_type, backfill_from, last_sync_at, last_error) VALUES (?,?,NOW(),\'\')
                     ON DUPLICATE KEY UPDATE last_sync_at = NOW(), last_error = \'\', backfill_from = COALESCE(backfill_from, VALUES(backfill_from))',
                    [$type, $state['backfill_from'] ?? $incFrom]
                );
            } catch (GoogleReauthNeeded $e) {
                return $this->finish(['ok' => false, 'reauth' => true, 'message' => $e->getMessage()]);
            } catch (Throwable $e) {
                $errors[] = "$type: " . $e->getMessage();
                Db::run('INSERT INTO d3_google_sync (data_type, last_error) VALUES (?,?) ON DUPLICATE KEY UPDATE last_error = VALUES(last_error)', [$type, mb_substr($e->getMessage(), 0, 500)]);
            }
        }

        // 2) Frühere Tage nachladen (Backfill)
        $progress = true;
        $failed = [];
        while ($progress && !$this->timeUp()) {
            $progress = false;
            foreach (self::TYPES as $type => $chunk) {
                if ($this->timeUp()) break;
                if (isset($failed[$type])) continue; // nicht im selben Lauf wiederholen
                $from = Db::value('SELECT backfill_from FROM d3_google_sync WHERE data_type = ?', [$type]);
                if (!$from || $from <= $target) continue;
                $start = max($target, (new DateTimeImmutable((string)$from))->modify("-$chunk days")->format('Y-m-d'));
                try {
                    $this->fetch($type, $start, (string)$from);
                    Db::run('UPDATE d3_google_sync SET backfill_from = ?, last_error = \'\' WHERE data_type = ?', [$start, $type]);
                    $progress = true;
                } catch (GoogleReauthNeeded $e) {
                    return $this->finish(['ok' => false, 'reauth' => true, 'message' => $e->getMessage()]);
                } catch (Throwable $e) {
                    $failed[$type] = true;
                    $errors[] = "$type (Verlauf): " . $e->getMessage();
                    Db::run('UPDATE d3_google_sync SET last_error = ? WHERE data_type = ?', [mb_substr($e->getMessage(), 0, 500), $type]);
                }
            }
        }

        return $this->finish([
            'ok' => !$errors,
            'reauth' => false,
            'message' => $errors ? 'Teilweise Fehler: ' . implode(' | ', array_slice($errors, 0, 3)) : 'Fitbit-Daten aktualisiert',
            'errors' => $errors,
        ]);
    }

    private function timeUp(): bool
    {
        return microtime(true) > $this->deadline;
    }

    private function finish(array $result): array
    {
        $days = array_keys($this->days);
        sort($days);
        if ($days) {
            Aggregator::recomputeDays($days);
        }
        $result['days'] = count($days);
        $result['requests'] = $this->requests;
        $result['backfill'] = self::backfillProgress();
        Db::run('INSERT INTO d3_sync_log (kind, items, info) VALUES (?,?,?)', ['google', count($days), mb_substr((string)$result['message'], 0, 500)]);
        return $result;
    }

    /** Fortschritt des Nachladens in Prozent (0–100) */
    public static function backfillProgress(): int
    {
        $days = max(3, (int)cfg('google_backfill_days', 90));
        $rows = Db::all('SELECT backfill_from FROM d3_google_sync');
        if (count($rows) < count(self::TYPES)) {
            return 0;
        }
        $today = new DateTimeImmutable(today());
        $min = 100;
        foreach ($rows as $r) {
            if (!$r['backfill_from']) return 0;
            $loaded = (int)$today->diff(new DateTimeImmutable($r['backfill_from']))->days;
            $min = min($min, (int)floor(min(1, $loaded / $days) * 100));
        }
        return $min;
    }

    // ------------------------------------------------------------------ Einzelne Datenarten

    private function fetch(string $type, string $from, string $to): void
    {
        switch ($type) {
            case 'sleep':
                $this->fetchSleep($from, $to);
                break;
            case 'resting_hr':
                foreach ($this->listAll('daily-resting-heart-rate', self::dateFilter('daily_resting_heart_rate.date', $from, $to), 1000) as $dp) {
                    $v = $dp['dailyRestingHeartRate'] ?? null;
                    if ($v && isset($v['date'], $v['beatsPerMinute'])) {
                        $this->upsertDaily('resting_hr', self::dateStr($v['date']), (float)$v['beatsPerMinute']);
                    }
                }
                break;
            case 'hrv':
                foreach ($this->listAll('daily-heart-rate-variability', self::dateFilter('daily_heart_rate_variability.date', $from, $to), 1000) as $dp) {
                    $v = $dp['dailyHeartRateVariability'] ?? null;
                    if ($v && isset($v['date'], $v['averageHeartRateVariabilityMilliseconds'])) {
                        $this->upsertDaily('hrv', self::dateStr($v['date']), (float)$v['averageHeartRateVariabilityMilliseconds']);
                    }
                }
                break;
            case 'steps':
                foreach ($this->dailyRollUp('steps', $from, $to) as $p) {
                    if (isset($p['steps']['countSum'], $p['civilStartTime']['date'])) {
                        $this->upsertDaily('steps', self::dateStr($p['civilStartTime']['date']), (float)$p['steps']['countSum'], true);
                    }
                }
                break;
            case 'active_kcal':
                foreach ($this->dailyRollUp('active-energy-burned', $from, $to) as $p) {
                    if (isset($p['activeEnergyBurned']['kcalSum'], $p['civilStartTime']['date'])) {
                        $this->upsertDaily('active_kcal', self::dateStr($p['civilStartTime']['date']), round((float)$p['activeEnergyBurned']['kcalSum']), true);
                    }
                }
                break;
            case 'total_kcal':
                foreach ($this->dailyRollUp('total-calories', $from, $to) as $p) {
                    if (isset($p['totalCalories']['kcalSum'], $p['civilStartTime']['date'])) {
                        $day = self::dateStr($p['civilStartTime']['date']);
                        $active = Db::value("SELECT value FROM d3_health_samples WHERE metric = 'active_kcal' AND source = ? AND start_at = ?", [self::SOURCE, "$day 00:00:00"]);
                        $basal = (float)$p['totalCalories']['kcalSum'] - (float)($active ?? 0);
                        if ($basal > 300) {
                            $this->upsertDaily('basal_kcal', $day, round($basal), true);
                        }
                    }
                }
                break;
            case 'exercise':
                $this->fetchExercise($from, $to);
                break;
            case 'heart_rate':
                $this->fetchHeartRate($from, $to);
                break;
        }
    }

    private function fetchSleep(string $from, string $to): void
    {
        $points = $this->listAll('sleep', self::dateFilter('sleep.interval.civil_end_time', $from, $to), 25);
        // Alte Fitbit-Schlafphasen im Zeitraum entfernen (Fitbit überarbeitet Schlaf manchmal nachträglich)
        Db::run("DELETE FROM d3_health_samples WHERE metric = 'sleep' AND source = ? AND end_at >= ? AND end_at < ?", [self::SOURCE, "$from 00:00:00", "$to 00:00:00"]);
        $map = ['LIGHT' => 'core', 'DEEP' => 'deep', 'REM' => 'rem', 'AWAKE' => 'awake', 'ASLEEP' => 'asleep', 'RESTLESS' => 'awake'];
        foreach ($points as $dp) {
            $s = $dp['sleep'] ?? null;
            if (!$s || !empty($s['metadata']['nap'])) {
                continue; // Nickerchen ignorieren (zählen nicht zum Nachtschlaf)
            }
            $rows = [];
            foreach ((array)($s['stages'] ?? []) as $st) {
                $stage = $map[$st['type'] ?? ''] ?? null;
                $a = self::toLocal($st['startTime'] ?? null);
                $b = self::toLocal($st['endTime'] ?? null);
                if ($stage && $a && $b && $b > $a) {
                    $rows[] = [$a, $b, null, $stage];
                }
            }
            if (!$rows) {
                // Ohne Phasen: ganze Schlafdauer als "asleep"
                $a = self::toLocal($s['interval']['startTime'] ?? null);
                $b = self::toLocal($s['interval']['endTime'] ?? null);
                if ($a && $b && $b > $a) {
                    $rows[] = [$a, $b, null, 'asleep'];
                }
            }
            foreach ($rows as [$a, $b, $v, $stage]) {
                $this->upsertSample('sleep', $a, $b, $v, $stage);
                $this->days[Aggregator::sleepDay($b)] = true;
            }
        }
    }

    private function fetchExercise(string $from, string $to): void
    {
        foreach ($this->listAll('exercise', self::dateFilter('exercise.interval.civil_start_time', $from, $to), 25) as $dp) {
            $e = $dp['exercise'] ?? null;
            if (!$e) continue;
            $start = self::toLocal($e['interval']['startTime'] ?? null);
            $end = self::toLocal($e['interval']['endTime'] ?? null);
            if (!$start || !$end || $end <= $start) continue;
            $type = (string)($e['exerciseType'] ?? 'OTHER');
            $name = trim((string)($e['displayName'] ?? '')) ?: self::germanName($type);
            $durMin = isset($e['activeDuration']) ? self::seconds((string)$e['activeDuration']) / 60 : ($end->getTimestamp() - $start->getTimestamp()) / 60;
            if ($durMin <= 0) continue;
            $ms = (array)($e['metricsSummary'] ?? []);
            $cat = self::category($type);
            Db::run(
                'INSERT INTO d3_workouts (source, activity, category, start_at, end_at, duration_min, energy_kcal, distance_km, avg_hr, load_score)
                 VALUES (?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE source = VALUES(source), category = VALUES(category), end_at = VALUES(end_at),
                   duration_min = VALUES(duration_min), energy_kcal = VALUES(energy_kcal), distance_km = VALUES(distance_km),
                   avg_hr = VALUES(avg_hr), load_score = VALUES(load_score)',
                [self::SOURCE, mb_substr($name, 0, 80), $cat, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'),
                 round($durMin, 1),
                 isset($ms['caloriesKcal']) ? round((float)$ms['caloriesKcal']) : null,
                 isset($ms['distanceMillimeters']) ? round((float)$ms['distanceMillimeters'] / 1e6, 2) : null,
                 isset($ms['averageHeartRateBeatsPerMinute']) ? (float)$ms['averageHeartRateBeatsPerMinute'] : null,
                 round($durMin * HealthIngest::LOAD_FACTORS[$cat], 1)]
            );
            $this->days[$start->format('Y-m-d')] = true;
        }
    }

    private function fetchHeartRate(string $from, string $to): void
    {
        $tz = new DateTimeZone(date_default_timezone_get());
        $utc = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable("$from 00:00:00", $tz))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $end = (new DateTimeImmutable("$to 00:00:00", $tz))->setTimezone($utc)->format('Y-m-d\TH:i:s\Z');
        $token = '';
        $rows = [];
        for ($page = 0; $page < 10; $page++) {
            $body = ['range' => ['startTime' => $start, 'endTime' => $end], 'windowSize' => '300s', 'pageSize' => 10000];
            if ($token !== '') $body['pageToken'] = $token;
            $this->requests++;
            $res = self::post('users/me/dataTypes/heart-rate/dataPoints:rollUp', $body);
            foreach ((array)($res['rollupDataPoints'] ?? []) as $p) {
                $hr = $p['heartRate'] ?? null;
                $ts = self::toLocal($p['startTime'] ?? null);
                if (!$hr || !$ts || !isset($hr['beatsPerMinuteAvg'])) continue;
                $rows[] = [$ts->format('Y-m-d H:i:s'), round((float)$hr['beatsPerMinuteAvg'], 1),
                           (float)($hr['beatsPerMinuteMin'] ?? $hr['beatsPerMinuteAvg']), (float)($hr['beatsPerMinuteMax'] ?? $hr['beatsPerMinuteAvg'])];
                $this->days[$ts->format('Y-m-d')] = true;
            }
            $token = (string)($res['nextPageToken'] ?? '');
            if ($token === '') break;
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            $place = implode(',', array_fill(0, count($chunk), '(?,?,?,?)'));
            Db::run("INSERT INTO d3_heart_rate (ts, bpm_avg, bpm_min, bpm_max) VALUES $place
                     ON DUPLICATE KEY UPDATE bpm_avg = VALUES(bpm_avg), bpm_min = VALUES(bpm_min), bpm_max = VALUES(bpm_max)", array_merge(...$chunk));
        }
    }

    private function refreshDevice(): void
    {
        $this->requests++;
        $res = self::get('users/me/pairedDevices');
        $devices = [];
        foreach ((array)($res['pairedDevices'] ?? []) as $d) {
            $devices[] = [
                'name' => (string)($d['deviceVersion'] ?? 'Fitbit'),
                'type' => (string)($d['deviceType'] ?? ''),
                'battery' => isset($d['batteryLevel']) ? (int)$d['batteryLevel'] : null,
                'battery_status' => (string)($d['batteryStatus'] ?? ''),
                'last_sync' => ($t = self::toLocal($d['lastSyncTime'] ?? null)) ? $t->format('Y-m-d H:i:s') : null,
                'features' => array_values(array_filter((array)($d['features'] ?? []), 'is_string')),
            ];
        }
        Settings::set('google_devices', $devices);
    }

    // ------------------------------------------------------------------ Speichern

    private function upsertSample(string $metric, DateTimeImmutable $a, DateTimeImmutable $b, ?float $value, string $text = ''): void
    {
        Db::run(
            'INSERT INTO d3_health_samples (metric, source, start_at, end_at, value, text_value) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$metric, self::SOURCE, $a->format('Y-m-d H:i:s'), $b->format('Y-m-d H:i:s'), $value, $text]
        );
    }

    /** Tageswert speichern; $wholeDay = Intervall über den ganzen Tag (Summen) */
    private function upsertDaily(string $metric, string $day, float $value, bool $wholeDay = false): void
    {
        $a = new DateTimeImmutable("$day 00:00:00");
        $b = $wholeDay ? $a->modify('+1 day') : $a;
        $this->upsertSample($metric, $a, $b, $value);
        $this->days[$day] = true;
    }

    // ------------------------------------------------------------------ Hilfen

    private static function dateFilter(string $field, string $from, string $to): string
    {
        return sprintf('%s >= "%s" AND %s < "%s"', $field, $from, $field, $to);
    }

    private static function dateObj(string $day): array
    {
        [$y, $m, $d] = array_map('intval', explode('-', $day));
        return ['year' => $y, 'month' => $m, 'day' => $d];
    }

    private static function dateStr(array $d): string
    {
        return sprintf('%04d-%02d-%02d', $d['year'] ?? 0, $d['month'] ?? 0, $d['day'] ?? 0);
    }

    /** RFC-3339-Zeit (UTC) → Ortszeit */
    public static function toLocal(?string $s): ?DateTimeImmutable
    {
        if (!$s) return null;
        try {
            return (new DateTimeImmutable($s))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        } catch (Exception $e) {
            return null;
        }
    }

    private static function seconds(string $duration): float
    {
        return (float)rtrim($duration, 's');
    }

    public static function category(string $type): string
    {
        $t = strtoupper($type);
        $groups = [
            'strength' => ['STRENGTH_TRAINING', 'WEIGHTLIFTING', 'WEIGHTS', 'FREE_WEIGHTS', 'WEIGHT_MACHINES', 'POWERLIFTING',
                'FUNCTIONAL_STRENGTH_TRAINING', 'BODY_WEIGHT', 'CALISTHENICS', 'CORE_TRAINING', 'RESISTANCE_BANDS', 'TRX'],
            'hiit' => ['HIIT', 'INTERVAL_WORKOUT', 'TABATA_WORKOUT', 'CROSSFIT', 'CIRCUIT_TRAINING', 'BOOTCAMP', 'CROSS_TRAINING',
                'BOXING', 'KICKBOXING', 'MARTIAL_ARTS', 'MUAY_THAI', 'JUMPING_ROPE'],
            'mobility' => ['YOGA', 'YOGA_BIKRAM', 'YOGA_HATHA', 'YOGA_POWER', 'YOGA_VINYASA', 'PILATES', 'STRETCHING', 'TAI_CHI', 'MEDITATE', 'BARRE_CLASS'],
            'walk' => ['WALKING', 'HIKING', 'NORDIC_WALKING', 'POWER_WALKING', 'TREADMILL_WALK', 'INCLINE_WALK', 'STROLLER_WALK',
                'WALK_WITH_WEIGHTS', 'RUCKING', 'BACKPACKING'],
            'cardio' => ['RUNNING', 'TRAIL_RUN', 'TREADMILL', 'INCLINE_RUN', 'BIKING', 'OUTDOOR_BIKE', 'MOUNTAIN_BIKE', 'SPINNING',
                'STATIONARY_BIKE', 'ASSAULT_BIKE', 'SWIMMING', 'SWIMMING_POOL', 'SWIMMING_OPEN_WATER', 'ROWING', 'ROWING_MACHINE',
                'ELLIPTICAL', 'STAIRCLIMBER', 'STEP_TRAINING', 'AEROBIC_WORKOUT', 'CARDIO_WORKOUT', 'DANCING', 'ZUMBA', 'SOCCER',
                'TENNIS', 'SQUASH', 'BADMINTON', 'BASKETBALL', 'HANDBALL', 'PADEL', 'VOLLEYBALL', 'CROSS_COUNTRY_SKI', 'SKIING', 'INDOOR_CLIMBING', 'CLIMBING'],
        ];
        foreach ($groups as $cat => $list) {
            if (in_array($t, $list, true)) return $cat;
        }
        return HealthIngest::workoutCategory($type);
    }

    private static function germanName(string $type): string
    {
        $names = ['STRENGTH_TRAINING' => 'Krafttraining', 'WEIGHTS' => 'Gewichte', 'RUNNING' => 'Laufen', 'WALKING' => 'Gehen',
            'BIKING' => 'Radfahren', 'HIIT' => 'HIIT', 'YOGA' => 'Yoga', 'SWIMMING' => 'Schwimmen', 'ELLIPTICAL' => 'Crosstrainer',
            'TREADMILL' => 'Laufband', 'HIKING' => 'Wandern', 'WORKOUT' => 'Training', 'OTHER' => 'Training'];
        return $names[strtoupper($type)] ?? ucfirst(strtolower(str_replace('_', ' ', $type)));
    }

    /** Für die App: Verbindungsstatus, Gerät, Fortschritt */
    public static function statusInfo(): array
    {
        $configured = GoogleAuth::configured();
        $st = $configured ? GoogleAuth::status() : null;
        $last = Db::one("SELECT received_at, info FROM d3_sync_log WHERE kind = 'google' ORDER BY id DESC LIMIT 1");
        $missing = [];
        if ($st && $st['scopes'] !== null && $st['scopes'] !== '') {
            foreach (GoogleAuth::SCOPES as $s) {
                if (!str_contains((string)$st['scopes'], $s)) {
                    $missing[] = str_replace('https://www.googleapis.com/auth/googlehealth.', '', $s);
                }
            }
        }
        return [
            'configured' => $configured,
            'config_issues' => $configured ? [] : GoogleAuth::configIssues(),
            'redirect_uri' => GoogleAuth::redirectUri(),
            'connected' => $st !== null && $st['status'] === 'connected',
            'reauth' => $st !== null && $st['status'] === 'reauth',
            'connected_at' => $st['connected_at'] ?? null,
            'error' => $st['last_error'] ?? '',
            'missing_scopes' => $missing,
            'devices' => $st ? (array)Settings::get('google_devices', []) : [],
            'last_sync' => $last['received_at'] ?? null,
            'last_message' => $last['info'] ?? '',
            'backfill_percent' => $st ? self::backfillProgress() : 0,
            'backfill_days' => (int)cfg('google_backfill_days', 90),
        ];
    }
}
