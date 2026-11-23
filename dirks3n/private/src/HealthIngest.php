<?php
declare(strict_types=1);

/**
 * Nimmt Gesundheitsdaten entgegen und speichert sie.
 *
 * Zwei Formate werden verstanden:
 *
 * 1) "Kurzbefehl-Text" (für die iPhone-Kurzbefehle-App, siehe docs/KURZBEFEHL.md):
 *      @hrv.value
 *      45
 *      47
 *      @hrv.start
 *      2026-11-23T06:01:00+01:00
 *      2026-11-23T06:31:00+01:00
 *    Jede Zeile nach einer Überschrift "@metrik.feld" ist ein Eintrag. Die n-te Zeile von
 *    ".value" gehört zur n-ten Zeile von ".start" (und ".end", ".source").
 *
 * 2) JSON:
 *      {"samples":[{"metric":"hrv","value":45,"start":"2026-11-23T06:01:00+01:00"}],
 *       "workouts":[{"type":"Krafttraining","start":"…","end":"…","energy_kcal":300}]}
 */
final class HealthIngest
{
    /** Erlaubte Metriken → Art der Zahl */
    public const METRICS = [
        'hrv'          => 'decimal', // ms
        'resting_hr'   => 'decimal', // bpm
        'heart_rate'   => 'decimal', // bpm
        'steps'        => 'count',
        'active_kcal'  => 'count',
        'basal_kcal'   => 'count',
        'exercise_min' => 'decimal',
        'weight'       => 'decimal', // kg
        'body_fat'     => 'percent',
        'vo2max'       => 'decimal',
        'sleep'        => 'stage',
    ];

    /** Alternative Namen, falls jemand andere Überschriften nutzt */
    private const ALIASES = [
        'heart_rate_variability' => 'hrv', 'herzfrequenzvariabilitaet' => 'hrv', 'hfv' => 'hrv',
        'restinghr' => 'resting_hr', 'ruhepuls' => 'resting_hr', 'resting_heart_rate' => 'resting_hr',
        'heartrate' => 'heart_rate', 'puls' => 'heart_rate',
        'schritte' => 'steps', 'step_count' => 'steps',
        'active_energy' => 'active_kcal', 'aktive_energie' => 'active_kcal',
        'basal_energy' => 'basal_kcal', 'basal_energy_burned' => 'basal_kcal', 'ruheenergie' => 'basal_kcal',
        'gewicht' => 'weight', 'body_mass' => 'weight',
        'koerperfett' => 'body_fat', 'body_fat_percentage' => 'body_fat',
        'schlaf' => 'sleep', 'sleep_analysis' => 'sleep',
        'workouts' => 'workout', 'training' => 'workout', 'trainings' => 'workout',
        'exercise' => 'exercise_min', 'trainingsminuten' => 'exercise_min',
    ];

    private array $affectedDays = [];
    private int $saved = 0;
    private array $warnings = [];

    /** @return array{saved:int, days:string[], warnings:string[]} */
    public function handle(string $raw, string $contentType = ''): array
    {
        $raw = trim($raw);
        $json = null;
        if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
            $json = json_decode($raw, true);
        }
        if (is_array($json)) {
            // Kurzbefehl schickt manchmal JSON {"text": "..."} → Textformat darin
            if (isset($json['text']) && is_string($json['text'])) {
                $this->parseText($json['text']);
            } else {
                $this->parseJson($json);
            }
        } else {
            $this->parseText($raw);
        }

        $days = array_keys($this->affectedDays);
        sort($days);
        if ($days) {
            Aggregator::recomputeDays($days);
        }
        Db::run('INSERT INTO d3_sync_log (kind, items, info) VALUES (?, ?, ?)', [
            'health', $this->saved, mb_substr(implode('; ', array_slice($this->warnings, 0, 5)), 0, 500),
        ]);
        return ['saved' => $this->saved, 'days' => $days, 'warnings' => array_slice($this->warnings, 0, 20)];
    }

    // ---------------------------------------------------------------- Textformat

    private function parseText(string $text): void
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $sections = [];   // metric => field => list
        $current = null;
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^@([a-z_äöü0-9]+)\.([a-z_]+)$/iu', $line, $m)) {
                $metric = $this->normalizeMetric($m[1]);
                $current = $metric ? [$metric, strtolower($m[2])] : null;
                if ($current && !isset($sections[$current[0]][$current[1]])) {
                    $sections[$current[0]][$current[1]] = [];
                }
                if (!$metric) {
                    $this->warnings[] = 'Unbekannte Metrik: ' . $m[1];
                }
                continue;
            }
            if ($current) {
                $sections[$current[0]][$current[1]][] = $line;
            }
        }

        foreach ($sections as $metric => $fields) {
            if ($metric === 'workout') {
                $this->importWorkoutColumns($fields);
                continue;
            }
            $values = $fields['value'] ?? [];
            $starts = $fields['start'] ?? ($fields['date'] ?? []);
            $ends = $fields['end'] ?? [];
            $sources = $fields['source'] ?? [];
            $rows = [];
            foreach ($values as $i => $v) {
                $start = $this->parseDate($starts[$i] ?? null);
                if (!$start) {
                    $this->warnings[] = "$metric: Datum in Zeile " . ($i + 1) . ' fehlt/unlesbar';
                    continue;
                }
                $end = $this->parseDate($ends[$i] ?? null) ?? $start;
                $rows[] = ['value' => $v, 'start' => $start, 'end' => $end, 'source' => $sources[$i] ?? ''];
            }
            $this->storeSamples($metric, $rows);
        }
    }

    private function importWorkoutColumns(array $f): void
    {
        $types = $f['type'] ?? ($f['value'] ?? []);
        $list = [];
        foreach ($types as $i => $type) {
            $list[] = [
                'type'        => $type,
                'start'       => $f['start'][$i] ?? null,
                'end'         => $f['end'][$i] ?? null,
                'duration'    => $f['duration'][$i] ?? null,
                'energy_kcal' => $f['energy'][$i] ?? null,
                'distance_km' => $f['distance'][$i] ?? null,
                'source'      => $f['source'][$i] ?? '',
            ];
        }
        $this->storeWorkouts($list);
    }

    // ---------------------------------------------------------------- JSON-Format

    private function parseJson(array $json): void
    {
        $byMetric = [];
        foreach ((array)($json['samples'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $metric = $this->normalizeMetric((string)($s['metric'] ?? ''));
            if (!$metric || $metric === 'workout') {
                $this->warnings[] = 'Unbekannte Metrik: ' . ($s['metric'] ?? '?');
                continue;
            }
            $start = $this->parseDate((string)($s['start'] ?? $s['date'] ?? ''));
            if (!$start) {
                continue;
            }
            $byMetric[$metric][] = [
                'value'  => $s['value'] ?? ($s['stage'] ?? null),
                'start'  => $start,
                'end'    => $this->parseDate((string)($s['end'] ?? '')) ?? $start,
                'source' => (string)($s['source'] ?? ''),
            ];
        }
        foreach ($byMetric as $metric => $rows) {
            $this->storeSamples($metric, $rows);
        }
        $this->storeWorkouts((array)($json['workouts'] ?? []));
    }

    // ---------------------------------------------------------------- Speichern

    private function storeSamples(string $metric, array $rows): void
    {
        $kind = self::METRICS[$metric];
        $batch = [];
        foreach ($rows as $r) {
            $text = '';
            $value = null;
            if ($kind === 'stage') {
                $text = $this->sleepStage((string)$r['value']);
                if ($text === '') {
                    $this->warnings[] = 'Unbekannte Schlafphase: ' . $r['value'];
                    continue;
                }
            } else {
                $value = $this->parseNumber((string)$r['value'], $kind);
                if ($value === null) {
                    $this->warnings[] = "$metric: Wert unlesbar ('" . mb_substr((string)$r['value'], 0, 20) . "')";
                    continue;
                }
                if (!$this->plausible($metric, $value)) {
                    $this->warnings[] = "$metric: unplausibler Wert $value übersprungen";
                    continue;
                }
            }
            /** @var DateTimeImmutable $start */
            $start = $r['start'];
            /** @var DateTimeImmutable $end */
            $end = $r['end'] < $start ? $start : $r['end'];
            $batch[] = [$metric, mb_substr(trim((string)$r['source']), 0, 60), $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $value, $text];
            foreach (Aggregator::daysForSample($metric, $start, $end) as $d) {
                $this->affectedDays[$d] = true;
            }
        }
        foreach (array_chunk($batch, 200) as $chunk) {
            $place = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?)'));
            $params = array_merge(...$chunk);
            Db::run(
                "INSERT INTO d3_health_samples (metric, source, start_at, end_at, value, text_value) VALUES $place
                 ON DUPLICATE KEY UPDATE value = VALUES(value)",
                $params
            );
            $this->saved += count($chunk);
        }
    }

    private function storeWorkouts(array $list): void
    {
        foreach ($list as $w) {
            if (!is_array($w)) {
                continue;
            }
            $type = trim((string)($w['type'] ?? $w['activity'] ?? $w['name'] ?? ''));
            $start = $this->parseDate((string)($w['start'] ?? ''));
            if ($type === '' || !$start) {
                $this->warnings[] = 'Training ohne Art/Startzeit übersprungen';
                continue;
            }
            $end = $this->parseDate((string)($w['end'] ?? ''));
            $durationMin = null;
            if (isset($w['duration']) && $w['duration'] !== null && $w['duration'] !== '') {
                $durationMin = $this->parseDuration((string)$w['duration']);
            }
            if (!$end && $durationMin) {
                $end = $start->modify('+' . (int)round($durationMin * 60) . ' seconds');
            }
            if (!$end) {
                $end = $start;
            }
            if ($durationMin === null) {
                $durationMin = max(0, ($end->getTimestamp() - $start->getTimestamp()) / 60);
            }
            if ($durationMin <= 0 || $durationMin > 24 * 60) {
                $this->warnings[] = "Training '$type' mit ungültiger Dauer übersprungen";
                continue;
            }
            $energy = isset($w['energy_kcal']) && $w['energy_kcal'] !== '' ? $this->parseNumber((string)$w['energy_kcal'], 'count') : null;
            $dist = isset($w['distance_km']) && $w['distance_km'] !== '' ? $this->parseNumber((string)$w['distance_km'], 'decimal') : null;
            $category = self::workoutCategory($type);
            $load = round($durationMin * self::LOAD_FACTORS[$category], 1);
            Db::run(
                'INSERT INTO d3_workouts (source, activity, category, start_at, end_at, duration_min, energy_kcal, distance_km, load_score)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE end_at = VALUES(end_at), duration_min = VALUES(duration_min),
                   energy_kcal = VALUES(energy_kcal), distance_km = VALUES(distance_km), category = VALUES(category), load_score = VALUES(load_score)',
                [mb_substr((string)($w['source'] ?? ''), 0, 60), mb_substr($type, 0, 80), $category,
                 $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), round($durationMin, 1), $energy, $dist, $load]
            );
            $this->saved++;
            $this->affectedDays[$start->format('Y-m-d')] = true;
        }
    }

    // ---------------------------------------------------------------- Hilfen

    public const LOAD_FACTORS = [
        'hiit' => 1.3, 'cardio' => 1.0, 'strength' => 0.9, 'other' => 0.6, 'walk' => 0.35, 'mobility' => 0.3,
    ];

    public static function workoutCategory(string $type): string
    {
        $t = mb_strtolower($type);
        $map = [
            'hiit'     => ['hiit', 'high intensity', 'hochintensiv', 'intervall', 'cross training', 'crosstraining', 'crossfit', 'boxen', 'boxing', 'kickbox'],
            'strength' => ['kraft', 'strength', 'functional', 'funktional', 'weight', 'gewicht', 'core', 'bodyweight', 'hantel'],
            'mobility' => ['yoga', 'pilates', 'stretch', 'dehn', 'mobil', 'flexib', 'cooldown', 'abkühl', 'mind', 'achtsam', 'tai chi'],
            'walk'     => ['walk', 'gehen', 'spazier', 'wander', 'hike', 'hiking'],
            'cardio'   => ['lauf', 'run', 'jogg', 'rad', 'cycl', 'bike', 'schwimm', 'swim', 'ruder', 'row', 'ellip', 'stair', 'treppe', 'cardio', 'tanz', 'dance', 'ski', 'fußball', 'soccer', 'tennis', 'squash', 'badminton', 'basketball', 'padel', 'spinning'],
        ];
        foreach ($map as $cat => $needles) {
            foreach ($needles as $n) {
                if (str_contains($t, $n)) {
                    return $cat;
                }
            }
        }
        return 'other';
    }

    private function normalizeMetric(string $name): ?string
    {
        $n = strtolower(trim($name));
        $n = strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', '-' => '_', ' ' => '_']);
        if ($n === 'workout' || isset(self::METRICS[$n])) {
            return $n;
        }
        return self::ALIASES[$n] ?? null;
    }

    /** Schlafphase (deutsch/englisch) → interner Name */
    private function sleepStage(string $v): string
    {
        $v = mb_strtolower(trim($v));
        $map = [
            'inbed' => ['in bed', 'inbed', 'im bett', 'bett'],
            'awake' => ['awake', 'wach'],
            'deep'  => ['deep', 'tief'],
            'rem'   => ['rem'],
            'core'  => ['core', 'kern', 'leicht', 'light'],
            'asleep'=> ['asleep', 'schlaf', 'unspecified', 'nicht angegeben', 'unbestimmt'],
        ];
        foreach ($map as $stage => $needles) {
            foreach ($needles as $n) {
                if (str_contains($v, $n)) {
                    return $stage;
                }
            }
        }
        // Zahlencodes von HealthKit: 0=inBed 1=asleep 2=awake 3=core 4=deep 5=rem
        $codes = ['0' => 'inbed', '1' => 'asleep', '2' => 'awake', '3' => 'core', '4' => 'deep', '5' => 'rem'];
        return $codes[$v] ?? '';
    }

    /**
     * Zahl aus Text lesen, z. B. "8.432", "8.432 Schritte", "45,5 ms", "82.4 kg", "18 %".
     */
    public function parseNumber(string $s, string $kind): ?float
    {
        $s = trim(str_replace(["\u{00A0}", "\u{202F}", ' '], '', $s));
        if (!preg_match('/-?[\d.,]+/', $s, $m)) {
            return null;
        }
        $n = $m[0];
        $hasDot = str_contains($n, '.');
        $hasComma = str_contains($n, ',');
        if ($hasDot && $hasComma) {
            // Das zuletzt stehende Zeichen ist das Dezimalzeichen
            if (strrpos($n, ',') > strrpos($n, '.')) {
                $n = str_replace(['.', ','], ['', '.'], $n);   // 1.234,5
            } else {
                $n = str_replace(',', '', $n);                // 1,234.5
            }
        } elseif ($hasComma) {
            // "8,432" bei Zählwerten = Tausender (englisch), sonst Dezimalkomma
            $n = ($kind === 'count' && preg_match('/^-?\d{1,3}(,\d{3})+$/', $n)) ? str_replace(',', '', $n) : str_replace(',', '.', $n);
        } elseif ($hasDot && $kind === 'count' && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $n)) {
            $n = str_replace('.', '', $n);                    // 8.432 Schritte
        }
        if (!is_numeric($n)) {
            return null;
        }
        $v = (float)$n;
        if ($kind === 'percent' && $v > 0 && $v <= 1 && !str_contains($s, '%')) {
            $v *= 100; // 0.18 → 18 %
        }
        return $v;
    }

    private function plausible(string $metric, float $v): bool
    {
        $ranges = [
            'hrv' => [1, 400], 'resting_hr' => [20, 150], 'heart_rate' => [20, 240], 'steps' => [0, 150000],
            'active_kcal' => [0, 15000], 'basal_kcal' => [0, 6000], 'exercise_min' => [0, 1440],
            'weight' => [25, 350], 'body_fat' => [2, 70], 'vo2max' => [10, 100],
        ];
        [$min, $max] = $ranges[$metric] ?? [-INF, INF];
        return $v >= $min && $v <= $max;
    }

    /** Dauer "1:02:03", "45 min", "3720" (Sekunden) oder "PT45M" → Minuten */
    private function parseDuration(string $s): ?float
    {
        $s = trim($s);
        if (preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
            return isset($m[3]) ? $m[1] * 60 + $m[2] + $m[3] / 60 : $m[1] + $m[2] / 60;
        }
        if (preg_match('/^P/i', $s)) {
            try {
                $i = new DateInterval(strtoupper($s));
                return $i->d * 1440 + $i->h * 60 + $i->i + $i->s / 60;
            } catch (Exception $e) {
                return null;
            }
        }
        $num = $this->parseNumber($s, 'decimal');
        if ($num === null) {
            return null;
        }
        $lower = mb_strtolower($s);
        if (str_contains($lower, 'std') || str_contains($lower, 'h')) {
            return $num * 60;
        }
        if (str_contains($lower, 'min')) {
            return $num;
        }
        if (str_contains($lower, 's')) {
            return $num / 60;
        }
        return $num > 600 ? $num / 60 : $num; // große Zahl = Sekunden
    }

    /** Datum aus verschiedenen Formaten (ISO 8601, deutsch, englisch) lesen */
    public function parseDate(?string $s): ?DateTimeImmutable
    {
        if ($s === null) {
            return null;
        }
        $s = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $s));
        if ($s === '') {
            return null;
        }
        $tz = new DateTimeZone(date_default_timezone_get());
        // Deutsches Format: 23.11.2026, 06:01 / 23.11.26 06:01:00 / 23.11.2026
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4}),?\s*(?:um\s*)?(?:(\d{1,2}):(\d{2})(?::(\d{2}))?)?/u', $s, $m)) {
            $year = strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3];
            $str = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $m[2], $m[1], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
            $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $str, $tz);
            return $d ?: null;
        }
        $s = preg_replace('/\s+(at|um)\s+/i', ' ', $s); // "23. Nov. 2026 um 06:01" / "Nov 23, 2026 at 6:01 AM"
        try {
            $d = new DateTimeImmutable($s, $tz);
        } catch (Exception $e) {
            return null;
        }
        $d = $d->setTimezone($tz);
        $year = (int)$d->format('Y');
        return ($year < 2000 || $year > 2100) ? null : $d;
    }
}
