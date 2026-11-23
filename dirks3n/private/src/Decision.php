<?php
declare(strict_types=1);

/**
 * Tägliche Trainingsentscheidung: OB, WAS und WIE INTENSIV.
 * 1. Kennzahlen werden ohne KI berechnet (Readiness).
 * 2. Ein einziger kurzer KI-Aufruf trifft die Entscheidung (falls verfügbar).
 * 3. Ohne KI (kein Schlüssel / Budget erreicht / Fehler) entscheiden feste Regeln.
 */
final class Decision
{
    public const KINDS = ['Kraft', 'Ausdauer', 'HIIT', 'Mobilität', 'Aktive Erholung', 'Ruhetag'];
    public const INTENSITIES = ['leicht', 'mittel', 'hart'];

    public static function get(string $day): ?array
    {
        $row = Db::one('SELECT * FROM d3_decisions WHERE day = ?', [$day]);
        if (!$row) {
            return null;
        }
        $row['train'] = (bool)$row['train'];
        $row['readiness'] = $row['readiness'] !== null ? (int)$row['readiness'] : null;
        $row['duration_min'] = (int)$row['duration_min'];
        $row['revisions'] = (int)$row['revisions'];
        $row['details'] = $row['details_json'] ? json_decode($row['details_json'], true) : [];
        unset($row['details_json']);
        return $row;
    }

    /** Entscheidung erzeugen (oder bestehende zurückgeben) */
    public static function make(string $day, bool $force = false, string $userNote = ''): array
    {
        $existing = self::get($day);
        if ($existing && !$force) {
            return $existing;
        }
        $ctx = self::context($day);
        $result = null;
        $engine = 'rules';
        $aiError = null;
        if (Claude::available()) {
            $ai = self::askAi($ctx, $userNote);
            if ($ai['ok']) {
                $result = self::sanitize($ai['data']);
                $engine = 'ai';
            } else {
                $aiError = $ai['error'];
                app_log("Entscheidung $day: KI nicht genutzt: $aiError");
            }
        }
        if ($result === null) {
            $result = self::rules($ctx);
            if ($aiError) {
                $result['reasoning'] .= " (Regel-Modus: $aiError)";
            } elseif (!Claude::configured()) {
                $result['reasoning'] .= ' (Regel-Modus: kein KI-Schlüssel hinterlegt)';
            } elseif (!Claude::available()) {
                $result['reasoning'] .= ' (Regel-Modus: KI-Monatsbudget erreicht)';
            }
        }

        $details = [
            'session' => $result['session'],
            'warnings' => $result['warnings'],
            'readiness' => $ctx['readiness'],
            'free_windows' => $ctx['calendar']['free_windows'],
            'user_note' => $userNote,
        ];
        Db::run(
            'INSERT INTO d3_decisions (day, readiness, train, kind, focus, intensity, duration_min, time_hint, reasoning, details_json, engine, revisions)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,0)
             ON DUPLICATE KEY UPDATE readiness = VALUES(readiness), train = VALUES(train), kind = VALUES(kind), focus = VALUES(focus),
               intensity = VALUES(intensity), duration_min = VALUES(duration_min), time_hint = VALUES(time_hint),
               reasoning = VALUES(reasoning), details_json = VALUES(details_json), engine = VALUES(engine), revisions = revisions + 1',
            [$day, $ctx['readiness']['score'], $result['train'] ? 1 : 0, $result['kind'], $result['focus'], $result['intensity'],
             $result['duration_min'], $result['time_hint'], $result['reasoning'],
             json_encode($details, JSON_UNESCAPED_UNICODE), $engine]
        );
        return self::get($day);
    }

    /** Alle Infos, die für die Entscheidung relevant sind – kompakt gehalten (= wenige Tokens) */
    public static function context(string $day): array
    {
        $d = new DateTimeImmutable($day);
        $readiness = Readiness::forDay($day);
        $events = Ics::eventsForDay($day);
        $free = Ics::freeWindows($day, $events);

        $workouts = [];
        foreach (Aggregator::dedupedWorkouts($d->modify('-10 days')->format('Y-m-d 00:00:00'), $day . ' 00:00:00') as $w) {
            $workouts[] = [
                'date' => substr($w['start_at'], 0, 10),
                'activity' => $w['activity'],
                'category' => $w['category'],
                'min' => (int)round((float)$w['duration_min']),
                'kcal' => $w['energy_kcal'] !== null ? (int)$w['energy_kcal'] : null,
            ];
        }
        $recent = Db::all(
            'SELECT day, train, kind, focus, intensity, duration_min FROM d3_decisions WHERE day < ? AND day >= ? ORDER BY day DESC',
            [$day, $d->modify('-7 days')->format('Y-m-d')]
        );
        $yesterday = Aggregator::dayValues($d->modify('-1 day')->format('Y-m-d'));

        return [
            'day' => $day,
            'weekday' => ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'][(int)$d->format('N') - 1],
            'profile' => array_filter(Settings::profile(), fn($v) => $v !== null && $v !== ''),
            'goals' => Goals::forPrompt(),
            'constraints' => array_filter(Goals::constraints(), fn($v) => $v !== null && $v !== '' && $v !== 0),
            'readiness' => $readiness,
            'yesterday' => [
                'steps' => isset($yesterday['steps']) ? (int)$yesterday['steps'] : null,
                'active_kcal' => isset($yesterday['active_kcal']) ? (int)$yesterday['active_kcal'] : null,
            ],
            'recent_workouts' => $workouts,
            'recent_plans' => array_map(fn($r) => [
                'day' => $r['day'], 'train' => (bool)$r['train'], 'kind' => $r['kind'], 'focus' => $r['focus'], 'intensity' => $r['intensity'],
            ], $recent),
            'calendar' => [
                'events' => array_map(fn($e) => [
                    'title' => mb_substr($e['title'], 0, 60),
                    'from' => (int)$e['all_day'] ? 'ganztägig' : substr($e['start_at'], 11, 5),
                    'to' => (int)$e['all_day'] ? '' : substr($e['end_at'], 11, 5),
                ], $events),
                'free_windows' => $free,
            ],
        ];
    }

    // ------------------------------------------------------------------ KI

    private static function askAi(array $ctx, string $userNote): array
    {
        $system = <<<TXT
Du bist der Trainingscoach der persönlichen Fitness-App "dirks3n". Du entscheidest jeden Tag, ob, was und wie intensiv trainiert wird.
Grundlagen: Erholungsdaten (HRV, Ruhepuls, Schlaf), Belastung der letzten Tage (acwr = akute/chronische Belastung; >1,3 erhöhtes Risiko, >1,5 hoch), bisherige Trainings, Ziele und freie Zeitfenster aus dem Kalender.
Regeln:
- Die Trainingshäufigkeit ist nicht vorgegeben; entscheide sie jeden Tag neu, passend zu den Zielen (goals, prio 1 = wichtigstes) und zur Erholung.
- Richte Art und Inhalt der Einheiten auf die Ziele aus und beachte constraints (Zeit pro Tag, Beschwerden, Ausstattung).
- Niedrige Bereitschaft oder deutlich erhöhter Ruhepuls/gesenkte HRV: leicht, Mobilität oder Ruhetag.
- Muskelgruppen brauchen ca. 48 h Pause; Kraft-Splits so wählen, dass sie zum Verlauf passen.
- Dauer muss in ein freies Zeitfenster passen und darf session_max_min aus dem Profil nicht überschreiten; schlage eine passende Uhrzeit vor.
- Fehlen Daten, entscheide vorsichtig mit dem, was da ist, und sag es kurz.
- reasoning: höchstens 3 kurze Sätze auf Deutsch, du-Form, mit den entscheidenden Zahlen.
- session: konkrete Übungen. Kraftübungen IMMER aus exercise_catalog wählen: exercise_id = Schlüssel, name = deutscher Name, detail im Format "4 × 6–8" (Sätze × Wiederholungen). Bevorzuge Übungen aus liked_exercises, vermeide disliked_exercises. Kein Gewicht angeben – das berechnet die App aus dem Verlauf. Andere Punkte (Aufwärmen, Cardio-Blöcke, Erholungs-Tipps) mit exercise_id "".
- Bei Ruhetag: 1–3 Erholungs-Tipps.
- warnings: nur echte Warnhinweise (z. B. Krankheitsanzeichen), sonst leere Liste.
TXT;
        $payload = $ctx;
        $payload['exercise_catalog'] = Training::catalogForPrompt();
        if (Exercises::available()) {
            $prefs = Db::all('SELECT id, preference FROM d3_exercises WHERE preference <> 0 AND curated = 1');
            $payload['liked_exercises'] = array_values(array_map(fn($r) => $r['id'], array_filter($prefs, fn($r) => (int)$r['preference'] > 0)));
            $payload['disliked_exercises'] = array_values(array_map(fn($r) => $r['id'], array_filter($prefs, fn($r) => (int)$r['preference'] < 0)));
        }
        if ($userNote !== '') {
            $payload['user_note'] = mb_substr($userNote, 0, 300);
        }
        $schema = [
            'type' => 'object',
            'properties' => [
                'train' => ['type' => 'boolean'],
                'kind' => ['type' => 'string', 'enum' => self::KINDS],
                'focus' => ['type' => 'string'],
                'intensity' => ['type' => 'string', 'enum' => self::INTENSITIES],
                'duration_min' => ['type' => 'integer'],
                'time_hint' => ['type' => 'string'],
                'reasoning' => ['type' => 'string'],
                'session' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'exercise_id' => ['type' => 'string', 'enum' => array_merge([''], array_keys(Exercises::CATALOG))],
                            'name' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                        ],
                        'required' => ['exercise_id', 'name', 'detail'],
                        'additionalProperties' => false,
                    ],
                ],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['train', 'kind', 'focus', 'intensity', 'duration_min', 'time_hint', 'reasoning', 'session', 'warnings'],
            'additionalProperties' => false,
        ];
        $content = [['type' => 'text', 'text' => "Daten für heute (JSON):\n" . json_encode($payload, JSON_UNESCAPED_UNICODE)]];
        return Claude::structured('decision', $system, $content, $schema, 4000, 'low');
    }

    private static function sanitize(array $r): array
    {
        $kind = in_array($r['kind'] ?? '', self::KINDS, true) ? $r['kind'] : 'Aktive Erholung';
        $session = [];
        foreach (array_slice((array)($r['session'] ?? []), 0, 12) as $s) {
            if (is_array($s)) {
                $exId = (string)($s['exercise_id'] ?? '');
                $session[] = [
                    'exercise_id' => isset(Exercises::CATALOG[$exId]) ? $exId : '',
                    'name' => isset(Exercises::CATALOG[$exId]) ? Exercises::CATALOG[$exId][0] : mb_substr((string)($s['name'] ?? ''), 0, 80),
                    'detail' => mb_substr((string)($s['detail'] ?? ''), 0, 160),
                ];
            }
        }
        return [
            'train' => (bool)($r['train'] ?? false) && $kind !== 'Ruhetag',
            'kind' => $kind,
            'focus' => mb_substr((string)($r['focus'] ?? ''), 0, 160),
            'intensity' => in_array($r['intensity'] ?? '', self::INTENSITIES, true) ? $r['intensity'] : 'leicht',
            'duration_min' => max(0, min(240, (int)($r['duration_min'] ?? 0))),
            'time_hint' => mb_substr((string)($r['time_hint'] ?? ''), 0, 120),
            'reasoning' => mb_substr((string)($r['reasoning'] ?? ''), 0, 900),
            'session' => $session,
            'warnings' => array_map(fn($w) => mb_substr((string)$w, 0, 200), array_slice((array)($r['warnings'] ?? []), 0, 5)),
        ];
    }

    // ------------------------------------------------------------------ Regeln (ohne KI)

    public static function rules(array $ctx): array
    {
        $r = $ctx['readiness'];
        $score = $r['score'];
        $f = $r['facts'];
        $profile = $ctx['profile'];
        $maxMin = (int)($profile['session_max_min'] ?: 75);
        $bestWindow = 0;
        $bestFrom = '';
        foreach ($ctx['calendar']['free_windows'] as $w) {
            if ($w['minutes'] > $bestWindow) {
                $bestWindow = $w['minutes'];
                $bestFrom = $w['from'];
            }
        }
        $timeHint = $bestFrom !== '' ? "ab $bestFrom (größtes freies Fenster: {$bestWindow} min)" : 'wenn es dir passt';
        $rhrDiff = $f['resting_hr']['diff_bpm'] ?? 0;
        $acwr = $f['load']['acwr'] ?? 1.0;
        $streak = $f['load']['training_days_in_row'] ?? 0;
        $sinceStrength = $f['load']['days_since_strength'] ?? null;
        $reasons = [];
        $warnings = [];

        if ($score === null) {
            $reasons[] = 'Noch keine Erholungsdaten (Schlaf/HRV/Ruhepuls) für heute – ich plane vorsichtig mittel.';
            $level = 'mittel';
        } else {
            $reasons[] = "Bereitschaft $score/100 ({$r['label']}).";
            $level = $score >= 75 ? 'hart' : ($score >= 60 ? 'mittel' : ($score >= 45 ? 'leicht' : 'ruhe'));
        }
        if (isset($f['sleep']['hours'])) {
            $h = (float)$f['sleep']['hours'];
            $reasons[] = 'Schlaf ' . self::de($h) . ' h (Ziel ' . self::de((float)$f['sleep']['target_h']) . ' h).';
            // Wenig Schlaf begrenzt die Intensität – egal wie gut die anderen Werte sind
            if ($h < 5 && in_array($level, ['hart', 'mittel'], true)) {
                $level = 'leicht';
                $reasons[] = 'Mit so wenig Schlaf heute nur locker.';
            } elseif ($h < 6 && $level === 'hart') {
                $level = 'mittel';
                $reasons[] = 'Wegen des kurzen Schlafs nur mittlere Intensität.';
            }
        }
        if ($rhrDiff >= 7) {
            $level = 'ruhe';
            $warnings[] = "Ruhepuls {$rhrDiff} Schläge über deinem Schnitt – möglicher Infekt oder Stress. Achte auf dich.";
        }
        if ($acwr > 1.5 && $level !== 'ruhe') {
            $level = 'leicht';
            $reasons[] = 'Deine Belastung der letzten Woche ist deutlich höher als üblich.';
        }
        if ($streak >= 4 && in_array($level, ['hart', 'mittel'], true)) {
            $level = 'leicht';
            $reasons[] = "$streak Trainingstage am Stück – heute etwas Entlastung.";
        }
        if ($bestWindow > 0 && $bestWindow < 30 && $level !== 'ruhe') {
            $level = 'ruhe';
            $reasons[] = 'Dein Kalender ist heute voll.';
        }

        $duration = min($maxMin, max(20, $bestWindow > 0 ? $bestWindow - 15 : $maxMin));

        if ($level === 'ruhe') {
            return self::result(false, 'Ruhetag', 'Regeneration', 'leicht', 0, '', $reasons, $warnings, [
                ['name' => 'Spaziergang', 'detail' => '20–30 min locker an der frischen Luft'],
                ['name' => 'Dehnen / Mobilität', 'detail' => '10 min, ganz entspannt'],
                ['name' => 'Früh schlafen', 'detail' => 'Heute 30 min früher ins Bett'],
            ]);
        }
        if ($level === 'leicht') {
            return self::result(true, 'Aktive Erholung', 'Zone-2-Cardio + Mobilität', 'leicht', min(40, $duration), $timeHint, $reasons, $warnings, [
                ['name' => 'Lockeres Cardio (Rad, Laufband, Crosstrainer)', 'detail' => '25 min in Zone 2 – du kannst dich noch unterhalten'],
                ['name' => 'Mobilität', 'detail' => 'Hüfte, Brustwirbelsäule, Schultern – je 2 × 60 s'],
            ]);
        }

        // mittel oder hart → Kraft, wenn letzte Krafteinheit ≥ 2 Tage her (oder nie), sonst Ausdauer
        if ($sinceStrength === null || $sinceStrength >= 2) {
            $lastFocus = '';
            foreach ($ctx['recent_plans'] as $p) {
                if ($p['kind'] === 'Kraft') {
                    $lastFocus = $p['focus'];
                    break;
                }
            }
            $variantB = str_ends_with(trim($lastFocus), ' A');
            $scheme = $level === 'hart' ? '4 × 6–8' : '3 × 8–10';
            $ex = fn(string $id, string $detail) => ['exercise_id' => $id, 'name' => Exercises::CATALOG[$id][0], 'detail' => $detail];
            $session = $variantB
                ? [$ex('Romanian_Deadlift', $scheme), $ex('Wide-Grip_Lat_Pulldown', $scheme), $ex('Incline_Dumbbell_Press', $scheme),
                   $ex('Dumbbell_Lunges', '3 × 10–12'), $ex('Side_Lateral_Raise', '3 × 12–15')]
                : [$ex('Barbell_Squat', $scheme), $ex('Barbell_Bench_Press_-_Medium_Grip', $scheme), $ex('Seated_Cable_Rows', $scheme),
                   $ex('Seated_Dumbbell_Press', '3 × 8–10'), $ex('Plank', '3 × 30–60')];
            $reasons[] = $sinceStrength === null ? 'Kein Krafttraining in den letzten Wochen erfasst.' : "Letztes Krafttraining vor $sinceStrength Tagen.";
            return self::result(true, 'Kraft', $variantB ? 'Ganzkörper B' : 'Ganzkörper A', $level, min($duration, 70), $timeHint, $reasons, $warnings, $session);
        }
        $reasons[] = 'Gestern Kraft – heute Ausdauer für Herz-Kreislauf und Erholung der Muskeln.';
        return self::result(true, 'Ausdauer', $level === 'hart' ? 'Intervalle' : 'Zone 2', $level, min($duration, 50), $timeHint, $reasons, $warnings,
            $level === 'hart'
                ? [['name' => 'Aufwärmen', 'detail' => '10 min locker'], ['name' => 'Intervalle', 'detail' => '6 × 2 min zügig / 2 min locker'], ['name' => 'Auslaufen', 'detail' => '8 min locker']]
                : [['name' => 'Gleichmäßiges Cardio', 'detail' => '35–45 min in Zone 2']]);
    }

    private static function de(float $n): string
    {
        return str_replace('.', ',', (string)round($n, 1));
    }

    private static function result(bool $train, string $kind, string $focus, string $intensity, int $duration, string $time, array $reasons, array $warnings, array $session): array
    {
        return [
            'train' => $train, 'kind' => $kind, 'focus' => $focus, 'intensity' => $intensity, 'duration_min' => $duration,
            'time_hint' => $time, 'reasoning' => implode(' ', $reasons), 'session' => $session, 'warnings' => $warnings,
        ];
    }
}
