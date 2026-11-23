<?php
declare(strict_types=1);

/** Berechnet aus den Rohdaten die Tageswerte (d3_daily_metrics). */
final class Aggregator
{
    /** Summen pro Tag (bei mehreren Quellen zählt die Quelle mit dem höchsten Wert → keine Doppelzählung) */
    private const SUM = ['steps', 'active_kcal', 'basal_kcal', 'exercise_min'];
    /** Durchschnitt pro Tag */
    private const AVG = ['hrv', 'resting_hr'];
    /** Letzter Wert des Tages */
    private const LAST = ['weight', 'body_fat', 'vo2max'];

    /** Alle Tagesmetriken, die hier berechnet werden (zum Aufräumen alter Werte) */
    private const COMPUTED = [
        'steps', 'active_kcal', 'basal_kcal', 'exercise_min', 'hrv', 'resting_hr', 'weight', 'body_fat', 'vo2max',
        'hr_avg', 'hr_min', 'hr_max',
        'sleep_min', 'sleep_deep_min', 'sleep_rem_min', 'sleep_core_min', 'sleep_awake_min', 'sleep_inbed_min', 'sleep_bedtime',
        'training_min', 'training_load', 'strength_min', 'workouts',
    ];

    /** Zu welchem Tag gehört ein Messwert? Schlaf zählt zum Aufwach-Tag. */
    public static function daysForSample(string $metric, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($metric === 'sleep') {
            return [self::sleepDay($end)];
        }
        return [$start->format('Y-m-d')];
    }

    /** Schlaf, der nach 20 Uhr endet, zählt zum nächsten Tag (Nacht), sonst zum selben Tag. */
    public static function sleepDay(DateTimeImmutable $end): string
    {
        return (int)$end->format('G') >= 20 ? $end->modify('+1 day')->format('Y-m-d') : $end->format('Y-m-d');
    }

    public static function recomputeDays(array $days): void
    {
        foreach (array_unique($days) as $day) {
            self::recomputeDay($day);
        }
    }

    public static function recomputeDay(string $day): void
    {
        $from = $day . ' 00:00:00';
        $to = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $vals = [];

        foreach (self::SUM as $m) {
            $rows = Db::all('SELECT source, SUM(value) AS s FROM d3_health_samples WHERE metric = ? AND start_at >= ? AND start_at < ? GROUP BY source', [$m, $from, $to]);
            if ($rows) {
                $vals[$m] = max(array_map(fn($r) => (float)$r['s'], $rows));
            }
        }
        foreach (self::AVG as $m) {
            $v = Db::value('SELECT AVG(value) FROM d3_health_samples WHERE metric = ? AND start_at >= ? AND start_at < ?', [$m, $from, $to]);
            if ($v !== null) {
                $vals[$m] = round((float)$v, 1);
            }
        }
        foreach (self::LAST as $m) {
            $v = Db::value('SELECT value FROM d3_health_samples WHERE metric = ? AND start_at >= ? AND start_at < ? ORDER BY start_at DESC LIMIT 1', [$m, $from, $to]);
            if ($v !== null) {
                $vals[$m] = round((float)$v, 2);
            }
        }
        $hr = Db::one('SELECT AVG(value) a, MIN(value) mi, MAX(value) ma, COUNT(*) c FROM d3_health_samples WHERE metric = ? AND start_at >= ? AND start_at < ?', ['heart_rate', $from, $to]);
        if ($hr && (int)$hr['c'] > 0) {
            $vals['hr_avg'] = round((float)$hr['a'], 1);
            $vals['hr_min'] = (float)$hr['mi'];
            $vals['hr_max'] = (float)$hr['ma'];
        }

        $vals = array_merge($vals, self::sleepFor($day), self::trainingFor($day, $from, $to));

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $place = implode(',', array_fill(0, count(self::COMPUTED), '?'));
            Db::run("DELETE FROM d3_daily_metrics WHERE day = ? AND metric IN ($place)", array_merge([$day], self::COMPUTED));
            foreach ($vals as $metric => $value) {
                Db::run('INSERT INTO d3_daily_metrics (day, metric, value) VALUES (?, ?, ?)', [$day, $metric, $value]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Schlaf der Nacht vor $day (Nachtfenster: Vortag 20:00 bis $day 20:00, nach Ende) */
    private static function sleepFor(string $day): array
    {
        $d = new DateTimeImmutable($day);
        $winFrom = $d->modify('-1 day')->format('Y-m-d') . ' 20:00:00';
        $winTo = $day . ' 20:00:00';
        $rows = Db::all('SELECT start_at, end_at, text_value FROM d3_health_samples WHERE metric = ? AND end_at >= ? AND end_at < ?', ['sleep', $winFrom, $winTo]);
        if (!$rows) {
            return [];
        }
        $byStage = [];
        foreach ($rows as $r) {
            $byStage[$r['text_value']][] = [strtotime($r['start_at']), strtotime($r['end_at'])];
        }
        $asleepIntervals = array_merge($byStage['asleep'] ?? [], $byStage['core'] ?? [], $byStage['deep'] ?? [], $byStage['rem'] ?? []);
        $asleep = self::unionMinutes($asleepIntervals);
        $out = [];
        if ($asleep > 0) {
            $out['sleep_min'] = $asleep;
            $out['sleep_deep_min'] = self::unionMinutes($byStage['deep'] ?? []);
            $out['sleep_rem_min'] = self::unionMinutes($byStage['rem'] ?? []);
            $out['sleep_core_min'] = max(0, $asleep - $out['sleep_deep_min'] - $out['sleep_rem_min']);
            $out['sleep_awake_min'] = self::unionMinutes($byStage['awake'] ?? []);
            $all = array_merge($asleepIntervals, $byStage['inbed'] ?? [], $byStage['awake'] ?? []);
            $out['sleep_inbed_min'] = isset($byStage['inbed']) ? self::unionMinutes($byStage['inbed']) : self::unionMinutes($all);
            // Einschlafzeit als Stunde (23.5 = 23:30, 24.5 = 00:30)
            $firstStart = min(array_column($asleepIntervals, 0));
            $h = (int)date('G', $firstStart) + (int)date('i', $firstStart) / 60;
            $out['sleep_bedtime'] = round($h < 15 ? $h + 24 : $h, 2);
        }
        return $out;
    }

    /** Vereinigung von Zeitintervallen in Minuten (überlappende Quellen werden nicht doppelt gezählt) */
    public static function unionMinutes(array $intervals): float
    {
        if (!$intervals) {
            return 0.0;
        }
        usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);
        $total = 0;
        [$cs, $ce] = $intervals[0];
        foreach ($intervals as [$s, $e]) {
            if ($s > $ce) {
                $total += $ce - $cs;
                [$cs, $ce] = [$s, $e];
            } else {
                $ce = max($ce, $e);
            }
        }
        $total += $ce - $cs;
        return round($total / 60, 1);
    }

    /** Trainings eines Tages, Überschneidungen (gleiches Training aus zwei Apps) werden entfernt */
    private static function trainingFor(string $day, string $from, string $to): array
    {
        $list = self::dedupedWorkouts($from, $to);
        if (!$list) {
            return [];
        }
        $out = ['training_min' => 0, 'training_load' => 0, 'strength_min' => 0, 'workouts' => count($list)];
        foreach ($list as $w) {
            $out['training_min'] += (float)$w['duration_min'];
            $out['training_load'] += (float)$w['load_score'];
            if ($w['category'] === 'strength') {
                $out['strength_min'] += (float)$w['duration_min'];
            }
        }
        return array_map(fn($v) => round($v, 1), $out);
    }

    public static function dedupedWorkouts(string $from, string $to): array
    {
        $rows = Db::all('SELECT * FROM d3_workouts WHERE start_at >= ? AND start_at < ? ORDER BY duration_min DESC', [$from, $to]);
        $kept = [];
        foreach ($rows as $w) {
            $s = strtotime($w['start_at']);
            $e = strtotime($w['end_at']);
            $dup = false;
            foreach ($kept as $k) {
                $overlap = min($e, strtotime($k['end_at'])) - max($s, strtotime($k['start_at']));
                if ($overlap > 0 && $overlap >= 0.5 * max(1, $e - $s)) {
                    $dup = true;
                    break;
                }
            }
            if (!$dup) {
                $kept[] = $w;
            }
        }
        usort($kept, fn($a, $b) => strcmp($a['start_at'], $b['start_at']));
        return $kept;
    }

    /** Tageswerte für einen Zeitraum: [metric => [day => value]] */
    public static function series(string $fromDay, string $toDay, array $metrics): array
    {
        $place = implode(',', array_fill(0, count($metrics), '?'));
        $rows = Db::all(
            "SELECT day, metric, value FROM d3_daily_metrics WHERE day >= ? AND day <= ? AND metric IN ($place) ORDER BY day",
            array_merge([$fromDay, $toDay], $metrics)
        );
        $out = array_fill_keys($metrics, []);
        foreach ($rows as $r) {
            $out[$r['metric']][$r['day']] = (float)$r['value'];
        }
        return $out;
    }

    public static function dayValues(string $day): array
    {
        $out = [];
        foreach (Db::all('SELECT metric, value FROM d3_daily_metrics WHERE day = ?', [$day]) as $r) {
            $out[$r['metric']] = (float)$r['value'];
        }
        return $out;
    }
}
