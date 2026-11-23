<?php
declare(strict_types=1);

/**
 * Workout-Tracking: Trainingseinheiten, Sätze, Gewichtsvorgaben (progressive Steigerung), Verlauf.
 *
 * Gewichtsvorgabe (ohne KI, deterministisch):
 *  - Letztes Mal alle Sätze am oberen Ende der Wiederholungen geschafft → + Steigerung (z. B. +2,5 kg)
 *  - Alle Sätze im Zielbereich → gleiches Gewicht, mehr Wiederholungen anpeilen
 *  - Mehr als die Hälfte der Sätze unter dem Zielbereich → ca. 5 % leichter
 *  - Noch nie gemacht → kein Gewicht vorgegeben, du trägst dein Startgewicht ein
 */
final class Training
{
    public static function available(): bool
    {
        return Aggregator::hasTable('d3_training_sessions');
    }

    // ------------------------------------------------------------------ Vorschlag für heute

    /**
     * Übungen für heute aus der Tagesentscheidung ableiten.
     * @return array{title:string, items:array}|null
     */
    public static function proposal(string $day): ?array
    {
        $dec = Decision::get($day);
        if (!$dec || !$dec['train'] || !in_array($dec['kind'], ['Kraft', 'HIIT'], true)) {
            return null;
        }
        $items = [];
        foreach ((array)($dec['details']['session'] ?? []) as $s) {
            $id = (string)($s['exercise_id'] ?? '');
            if ($id === '' || !isset(Exercises::CATALOG[$id])) {
                $id = Exercises::idByGermanName((string)($s['name'] ?? '')) ?? '';
            }
            if ($id === '' || !Exercises::get($id)) {
                continue; // z. B. Aufwärmen oder unbekannte Übung
            }
            [$sets, $min, $max] = self::parseScheme((string)($s['detail'] ?? ''), Exercises::meta($id)['reps']);
            $items[] = ['exercise_id' => $id, 'sets' => $sets, 'reps_min' => $min, 'reps_max' => $max, 'note' => (string)($s['detail'] ?? '')];
        }
        if (!$items) {
            return null;
        }
        foreach ($items as &$it) {
            $it['weight'] = self::suggestWeight($it['exercise_id'], $it['reps_min'], $it['reps_max']);
            $it['last'] = self::lastPerformance($it['exercise_id']);
            $it['exercise'] = Exercises::present(Exercises::get($it['exercise_id']));
        }
        unset($it);
        return ['title' => trim($dec['kind'] . ' · ' . $dec['focus'], ' ·'), 'intensity' => $dec['intensity'], 'items' => $items];
    }

    /** "4 × 6–8" / "3x10" / "3 × 45 s" → [Sätze, Wdh. min, Wdh. max] */
    public static function parseScheme(string $detail, array $defaultReps): array
    {
        if (preg_match('/(\d+)\s*[×x]\s*(\d+)(?:\s*[–\-]\s*(\d+))?/u', $detail, $m)) {
            $sets = max(1, min(8, (int)$m[1]));
            $min = (int)$m[2];
            $max = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : $min;
            return [$sets, $min, max($min, $max)];
        }
        return [3, $defaultReps[0], $defaultReps[1]];
    }

    // ------------------------------------------------------------------ Progression

    public static function suggestWeight(string $exerciseId, int $min, int $max): ?float
    {
        $meta = Exercises::meta($exerciseId);
        if ($meta['bodyweight']) {
            return 0.0;
        }
        $last = self::lastSessionSets($exerciseId);
        if (!$last) {
            return null;
        }
        $weights = array_map(fn($s) => (float)$s['weight'], $last);
        $w = max($weights);
        $working = array_values(array_filter($last, fn($s) => (float)$s['weight'] >= $w - 0.01));
        $hitTop = count(array_filter($working, fn($s) => (int)$s['reps'] >= $max)) === count($working);
        $below = count(array_filter($working, fn($s) => (int)$s['reps'] < $min));
        $inc = (float)($meta['increment'] ?: 2.5);
        if ($hitTop) {
            $w += $inc;
        } elseif ($below > count($working) / 2) {
            $w = $w * 0.95;
        }
        return self::roundTo($w, $inc >= 2 ? ($inc >= 5 ? 2.5 : 1.25) : 0.5);
    }

    private static function roundTo(float $w, float $step): float
    {
        return round(round($w / $step) * $step, 2);
    }

    /** Erledigte Sätze der letzten abgeschlossenen Einheit mit dieser Übung */
    private static function lastSessionSets(string $exerciseId, ?int $excludeSession = null): array
    {
        $sid = Db::value(
            "SELECT s.id FROM d3_training_sessions s JOIN d3_training_sets t ON t.session_id = s.id
             WHERE t.exercise_id = ? AND t.done = 1 AND s.status = 'done'" . ($excludeSession ? ' AND s.id <> ' . (int)$excludeSession : '') . '
             ORDER BY s.started_at DESC LIMIT 1',
            [$exerciseId]
        );
        if (!$sid) {
            return [];
        }
        return Db::all('SELECT reps, weight FROM d3_training_sets WHERE session_id = ? AND exercise_id = ? AND done = 1 AND reps IS NOT NULL ORDER BY set_no', [$sid, $exerciseId]);
    }

    /** Kurzinfo "letztes Mal": z. B. "3 × 8 @ 60 kg (12.11.)" */
    public static function lastPerformance(string $exerciseId, ?int $excludeSession = null): ?string
    {
        $row = Db::one(
            "SELECT s.id, s.day FROM d3_training_sessions s JOIN d3_training_sets t ON t.session_id = s.id
             WHERE t.exercise_id = ? AND t.done = 1 AND s.status = 'done'" . ($excludeSession ? ' AND s.id <> ' . (int)$excludeSession : '') . '
             ORDER BY s.started_at DESC LIMIT 1',
            [$exerciseId]
        );
        if (!$row) {
            return null;
        }
        $sets = Db::all('SELECT reps, weight FROM d3_training_sets WHERE session_id = ? AND exercise_id = ? AND done = 1 ORDER BY set_no', [$row['id'], $exerciseId]);
        $parts = array_map(fn($s) => (int)$s['reps'] . ((float)$s['weight'] > 0 ? '×' . self::kg((float)$s['weight']) : ''), $sets);
        return implode(' · ', $parts) . ' (' . date('d.m.', strtotime($row['day'])) . ')';
    }

    private static function kg(float $w): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($w, 2, '.', ''), '0'), '.'));
    }

    // ------------------------------------------------------------------ Einheiten

    public static function active(): ?array
    {
        $id = Db::value("SELECT id FROM d3_training_sessions WHERE status = 'active' ORDER BY started_at DESC LIMIT 1");
        return $id ? self::session((int)$id) : null;
    }

    public static function start(string $day, bool $fromPlan): array
    {
        if ($a = self::active()) {
            return $a; // nur eine aktive Einheit gleichzeitig
        }
        $plan = $fromPlan ? self::proposal($day) : null;
        Db::run('INSERT INTO d3_training_sessions (day, title, origin, status, started_at) VALUES (?,?,?,?,NOW())', [
            $day, $plan['title'] ?? 'Freies Training', $plan ? 'plan' : 'free', 'active',
        ]);
        $sid = (int)Db::pdo()->lastInsertId();
        if ($plan) {
            foreach ($plan['items'] as $i => $it) {
                self::insertSets($sid, $it['exercise_id'], $i + 1, $it['sets'], $it['reps_min'], $it['reps_max'], $it['weight']);
            }
        }
        return self::session($sid);
    }

    private static function insertSets(int $sid, string $exId, int $order, int $sets, int $min, int $max, ?float $weight): void
    {
        $rest = (int)Exercises::meta($exId)['rest'];
        if (!isset(Exercises::CATALOG[$exId]) && ($r = Exercises::get($exId))) {
            $rest = $r['mechanic'] === 'compound' ? 120 : 75;
        }
        for ($n = 1; $n <= $sets; $n++) {
            Db::run('INSERT INTO d3_training_sets (session_id, exercise_id, ex_order, set_no, target_reps_min, target_reps_max, target_weight, weight, rest_sec)
                     VALUES (?,?,?,?,?,?,?,?,?)', [$sid, $exId, $order, $n, $min, $max, $weight, $weight, $rest]);
        }
    }

    public static function addExercise(int $sid, string $exId): array
    {
        self::requireActive($sid);
        if (!Exercises::get($exId)) {
            throw new InvalidArgumentException('Unbekannte Übung');
        }
        $order = (int)Db::value('SELECT COALESCE(MAX(ex_order), 0) + 1 FROM d3_training_sets WHERE session_id = ?', [$sid]);
        $reps = Exercises::meta($exId)['reps'];
        self::insertSets($sid, $exId, $order, 3, $reps[0], $reps[1], self::suggestWeight($exId, $reps[0], $reps[1]));
        return self::session($sid);
    }

    public static function addSet(int $sid, string $exId): array
    {
        self::requireActive($sid);
        $last = Db::one('SELECT * FROM d3_training_sets WHERE session_id = ? AND exercise_id = ? ORDER BY set_no DESC LIMIT 1', [$sid, $exId]);
        if (!$last) {
            throw new InvalidArgumentException('Übung nicht in dieser Einheit');
        }
        Db::run('INSERT INTO d3_training_sets (session_id, exercise_id, ex_order, set_no, target_reps_min, target_reps_max, target_weight, weight, rest_sec)
                 VALUES (?,?,?,?,?,?,?,?,?)', [$sid, $exId, $last['ex_order'], (int)$last['set_no'] + 1, $last['target_reps_min'], $last['target_reps_max'],
                 $last['target_weight'], $last['weight'] ?? $last['target_weight'], $last['rest_sec']]);
        return self::session($sid);
    }

    public static function removeSet(int $setId): array
    {
        $row = Db::one('SELECT session_id FROM d3_training_sets WHERE id = ?', [$setId]);
        if (!$row) {
            throw new InvalidArgumentException('Satz nicht gefunden');
        }
        self::requireActive((int)$row['session_id']);
        Db::run('DELETE FROM d3_training_sets WHERE id = ?', [$setId]);
        return self::session((int)$row['session_id']);
    }

    /** Satz speichern / abhaken. Gibt die Pausenlänge zurück. */
    public static function updateSet(int $setId, ?int $reps, ?float $weight, bool $done): array
    {
        $row = Db::one('SELECT * FROM d3_training_sets WHERE id = ?', [$setId]);
        if (!$row) {
            throw new InvalidArgumentException('Satz nicht gefunden');
        }
        self::requireActive((int)$row['session_id']);
        if ($reps !== null && ($reps < 0 || $reps > 500)) throw new InvalidArgumentException('Wiederholungen unplausibel');
        if ($weight !== null && ($weight < 0 || $weight > 700)) throw new InvalidArgumentException('Gewicht unplausibel');
        Db::run('UPDATE d3_training_sets SET reps = ?, weight = ?, done = ?, done_at = ? WHERE id = ?', [
            $reps, $weight, $done ? 1 : 0, $done ? now()->format('Y-m-d H:i:s') : null, $setId,
        ]);
        return ['rest_sec' => (int)$row['rest_sec'], 'session' => self::session((int)$row['session_id'])];
    }

    private static function requireActive(int $sid): void
    {
        $st = Db::value('SELECT status FROM d3_training_sessions WHERE id = ?', [$sid]);
        if ($st !== 'active') {
            throw new InvalidArgumentException('Diese Einheit ist bereits abgeschlossen.');
        }
    }

    public static function discard(int $sid): void
    {
        self::requireActive($sid);
        Db::run('DELETE FROM d3_training_sets WHERE session_id = ?', [$sid]);
        Db::run('DELETE FROM d3_training_sessions WHERE id = ?', [$sid]);
    }

    /** Training beenden: speichern, als Training zählen (Belastung), Rekorde ermitteln */
    public static function finish(int $sid): array
    {
        self::requireActive($sid);
        Db::run('DELETE FROM d3_training_sets WHERE session_id = ? AND done = 0', [$sid]); // nicht gemachte Sätze verwerfen
        $s = Db::one('SELECT * FROM d3_training_sessions WHERE id = ?', [$sid]);
        $count = (int)Db::value('SELECT COUNT(*) FROM d3_training_sets WHERE session_id = ?', [$sid]);
        if ($count === 0) {
            self::discard($sid);
            return ['discarded' => true];
        }
        $end = now();
        $lastDone = Db::value('SELECT MAX(done_at) FROM d3_training_sets WHERE session_id = ?', [$sid]);
        if ($lastDone && strtotime((string)$lastDone) < $end->getTimestamp() - 1800) {
            $end = new DateTimeImmutable((string)$lastDone); // App erst später geschlossen → letzter Satz zählt
        }
        Db::run("UPDATE d3_training_sessions SET status = 'done', finished_at = ? WHERE id = ?", [$end->format('Y-m-d H:i:s'), $sid]);

        // Als Training für Belastung & Statistik eintragen (Überschneidung mit Fitbit-Aufzeichnung wird erkannt)
        $minutes = max(1, ($end->getTimestamp() - strtotime($s['started_at'])) / 60);
        Db::run(
            "INSERT INTO d3_workouts (source, activity, category, start_at, end_at, duration_min, load_score)
             VALUES ('dirks3n', ?, 'strength', ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE end_at = VALUES(end_at), duration_min = VALUES(duration_min), load_score = VALUES(load_score)",
            [mb_substr('Krafttraining · ' . $s['title'], 0, 80), $s['started_at'], $end->format('Y-m-d H:i:s'), round($minutes, 1),
             round($minutes * HealthIngest::LOAD_FACTORS['strength'], 1)]
        );
        Aggregator::recomputeDay(substr($s['started_at'], 0, 10));
        return self::summary($sid);
    }

    public static function summary(int $sid): array
    {
        $s = self::session($sid);
        $volume = 0.0;
        $sets = 0;
        $records = [];
        foreach ($s['exercises'] as $ex) {
            $best = 0.0;
            foreach ($ex['sets'] as $set) {
                if (!$set['done']) continue;
                $sets++;
                $volume += (float)$set['weight'] * (int)$set['reps'];
                $best = max($best, self::e1rm((float)$set['weight'], (int)$set['reps']));
            }
            $prev = (float)Db::value(
                "SELECT MAX(t.weight * (1 + t.reps / 30)) FROM d3_training_sets t JOIN d3_training_sessions s ON s.id = t.session_id
                 WHERE t.exercise_id = ? AND t.done = 1 AND s.status = 'done' AND s.id <> ? AND t.reps <= 12",
                [$ex['exercise']['id'], $sid]
            );
            if ($best > 0 && $prev > 0 && $best > $prev + 0.01) {
                $records[] = ['exercise' => $ex['exercise']['name'], 'e1rm' => round($best, 1), 'before' => round($prev, 1)];
            }
        }
        $s['summary'] = ['volume_kg' => round($volume), 'sets' => $sets, 'records' => $records,
            'minutes' => $s['finished_at'] ? (int)round((strtotime($s['finished_at']) - strtotime($s['started_at'])) / 60) : null];
        return $s;
    }

    /** Geschätztes 1-Wiederholungs-Maximum (Epley) – nur bis 12 Wdh. sinnvoll */
    public static function e1rm(float $w, int $reps): float
    {
        if ($w <= 0 || $reps <= 0 || $reps > 12) return 0.0;
        return $reps === 1 ? $w : $w * (1 + $reps / 30);
    }

    public static function session(int $sid): array
    {
        $s = Db::one('SELECT * FROM d3_training_sessions WHERE id = ?', [$sid]);
        if (!$s) {
            throw new InvalidArgumentException('Einheit nicht gefunden');
        }
        $rows = Db::all('SELECT * FROM d3_training_sets WHERE session_id = ? ORDER BY ex_order, set_no', [$sid]);
        $groups = [];
        foreach ($rows as $r) {
            $key = $r['ex_order'] . '|' . $r['exercise_id'];
            if (!isset($groups[$key])) {
                $ex = Exercises::get($r['exercise_id']);
                $groups[$key] = [
                    'exercise' => $ex ? Exercises::present($ex) : ['id' => $r['exercise_id'], 'name' => $r['exercise_id'], 'images' => 0, 'muscles' => [], 'meta' => Exercises::meta($r['exercise_id'])],
                    'last' => self::lastPerformance($r['exercise_id'], $sid),
                    'sets' => [],
                ];
            }
            $groups[$key]['sets'][] = [
                'id' => (int)$r['id'], 'set_no' => (int)$r['set_no'],
                'target_reps_min' => $r['target_reps_min'] !== null ? (int)$r['target_reps_min'] : null,
                'target_reps_max' => $r['target_reps_max'] !== null ? (int)$r['target_reps_max'] : null,
                'target_weight' => $r['target_weight'] !== null ? (float)$r['target_weight'] : null,
                'reps' => $r['reps'] !== null ? (int)$r['reps'] : null,
                'weight' => $r['weight'] !== null ? (float)$r['weight'] : null,
                'done' => (bool)$r['done'], 'rest_sec' => (int)$r['rest_sec'],
            ];
        }
        return [
            'id' => (int)$s['id'], 'day' => $s['day'], 'title' => $s['title'], 'status' => $s['status'], 'origin' => $s['origin'],
            'started_at' => $s['started_at'], 'finished_at' => $s['finished_at'],
            'exercises' => array_values($groups),
        ];
    }

    public static function history(int $limit = 30): array
    {
        $rows = Db::all(
            "SELECT s.id, s.day, s.title, s.started_at, s.finished_at,
                    COUNT(t.id) AS sets, COALESCE(SUM(t.weight * t.reps), 0) AS volume, COUNT(DISTINCT t.exercise_id) AS exercises
             FROM d3_training_sessions s LEFT JOIN d3_training_sets t ON t.session_id = s.id AND t.done = 1
             WHERE s.status = 'done' GROUP BY s.id ORDER BY s.started_at DESC LIMIT " . max(1, min(100, $limit))
        );
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'day' => $r['day'], 'title' => $r['title'], 'sets' => (int)$r['sets'], 'exercises' => (int)$r['exercises'],
            'volume_kg' => round((float)$r['volume']),
            'minutes' => $r['finished_at'] ? (int)round((strtotime($r['finished_at']) - strtotime($r['started_at'])) / 60) : null,
        ], $rows);
    }

    /** Verlauf einer Übung: je Einheit bestes Gewicht, geschätztes 1RM, Volumen */
    public static function exerciseProgress(string $exId): array
    {
        $rows = Db::all(
            "SELECT s.day, t.reps, t.weight FROM d3_training_sets t JOIN d3_training_sessions s ON s.id = t.session_id
             WHERE t.exercise_id = ? AND t.done = 1 AND s.status = 'done' ORDER BY s.started_at",
            [$exId]
        );
        $byDay = [];
        foreach ($rows as $r) {
            $d = $r['day'];
            $w = (float)$r['weight'];
            $reps = (int)$r['reps'];
            $byDay[$d] ??= ['day' => $d, 'top_weight' => 0.0, 'e1rm' => 0.0, 'volume' => 0.0, 'reps_total' => 0, 'best_set' => ''];
            if ($w > $byDay[$d]['top_weight'] || ($w === $byDay[$d]['top_weight'] && $byDay[$d]['best_set'] === '')) {
                $byDay[$d]['top_weight'] = $w;
                $byDay[$d]['best_set'] = $reps . ($w > 0 ? ' × ' . self::kg($w) . ' kg' : ' Wdh.');
            }
            $byDay[$d]['e1rm'] = max($byDay[$d]['e1rm'], round(self::e1rm($w, $reps), 1));
            $byDay[$d]['volume'] += $w * $reps;
            $byDay[$d]['reps_total'] += $reps;
        }
        $list = array_values($byDay);
        $records = [
            'top_weight' => $list ? max(array_column($list, 'top_weight')) : null,
            'e1rm' => $list ? max(array_column($list, 'e1rm')) : null,
            'volume' => $list ? round(max(array_column($list, 'volume'))) : null,
            'sessions' => count($list),
        ];
        return ['sessions' => $list, 'records' => $records];
    }

    /** Für die KI: Liste der Kernübungen (ID → deutscher Name), damit Pläne exakt zugeordnet werden */
    public static function catalogForPrompt(): array
    {
        $out = [];
        foreach (Exercises::CATALOG as $id => $c) {
            $out[$id] = $c[0];
        }
        return $out;
    }
}
