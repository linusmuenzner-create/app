<?php
declare(strict_types=1);

/**
 * Ziel-Chat: Du schreibst frei deine Ziele, der Coach fragt nach, bis alles klar ist,
 * und macht daraus strukturierte Ziele. Erst nach deiner Bestätigung werden sie gespeichert.
 *
 * Kostenbewusst: Jede Runde ist ein einziger kurzer KI-Aufruf. Der bisherige Verlauf wird als
 * kompakte Abschrift mitgeschickt (keine langen Antwortketten).
 */
final class Goals
{
    public const CATEGORIES = [
        'strength' => 'Kraft', 'muscle' => 'Muskelaufbau', 'running_speed' => 'Schneller laufen',
        'endurance' => 'Ausdauer', 'fat_loss' => 'Fett verlieren', 'health' => 'Gesundheit', 'other' => 'Sonstiges',
    ];

    private const THREAD = 'goals';
    private const MAX_TURNS = 30;

    public static function available(): bool
    {
        return Aggregator::hasTable('d3_goals');
    }

    /** @return array<int, array> aktive Ziele, wichtigste zuerst */
    public static function active(): array
    {
        if (!self::available()) {
            return [];
        }
        return array_map([self::class, 'present'], Db::all("SELECT * FROM d3_goals WHERE status = 'active' ORDER BY priority, id"));
    }

    private static function present(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'category' => $r['category'], 'category_label' => self::CATEGORIES[$r['category']] ?? $r['category'],
            'title' => $r['title'], 'metric' => $r['metric'], 'target' => $r['target'], 'baseline' => $r['baseline'],
            'deadline' => $r['deadline'], 'priority' => (int)$r['priority'], 'notes' => $r['notes'],
        ];
    }

    /** Muss der Ziel-Chat nach dem Login angezeigt werden? */
    public static function needsSetup(): bool
    {
        return self::available() && !self::active() && !Settings::get('goals_skipped', false);
    }

    public static function constraints(): array
    {
        return (array)Settings::get('constraints', []);
    }

    /** Kompakte Fassung für die Trainingsentscheidung (wenige Tokens) */
    public static function forPrompt(): array
    {
        $out = [];
        foreach (self::active() as $g) {
            $out[] = array_filter([
                'prio' => $g['priority'], 'type' => $g['category'], 'goal' => $g['title'], 'target' => $g['target'],
                'now' => $g['baseline'], 'until' => $g['deadline'], 'notes' => $g['notes'],
            ], fn($v) => $v !== null && $v !== '');
        }
        return $out;
    }

    // ------------------------------------------------------------------ Chat

    public static function messages(): array
    {
        if (!self::available()) {
            return [];
        }
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'role' => $r['role'], 'content' => $r['content'],
            'proposal' => $r['meta_json'] ? json_decode($r['meta_json'], true) : null,
            'at' => $r['created_at'],
        ], Db::all('SELECT * FROM d3_chat_messages WHERE thread = ? ORDER BY id', [self::THREAD]));
    }

    /** Neues Gespräch beginnen (z. B. "Ziele ändern"), alte Nachrichten bleiben nicht im Chat */
    public static function reset(): void
    {
        Db::run('DELETE FROM d3_chat_messages WHERE thread = ?', [self::THREAD]);
    }

    /** Begrüßung des Coachs (ohne KI-Aufruf) */
    public static function greeting(): string
    {
        $goals = self::active();
        if ($goals) {
            $list = implode(', ', array_map(fn($g) => $g['title'], $goals));
            return "Deine aktuellen Ziele sind: $list. Was möchtest du ändern, ergänzen oder streichen?";
        }
        return 'Hi! Ich bin dein dirks3n-Coach. Erzähl mir frei, was du erreichen willst – zum Beispiel '
             . '„5 km unter 20 Minuten“, „4 kg Muskeln aufbauen“ oder „einfach fitter werden“. '
             . 'Ich frage nach, wenn mir etwas fehlt.';
    }

    /** Eine Chat-Runde: Nachricht speichern, Coach antworten lassen */
    public static function send(string $text): array
    {
        $text = trim(mb_substr($text, 0, 1500));
        if ($text === '') {
            throw new InvalidArgumentException('Bitte schreib etwas.');
        }
        if ((int)Db::value('SELECT COUNT(*) FROM d3_chat_messages WHERE thread = ?', [self::THREAD]) >= self::MAX_TURNS * 2) {
            throw new InvalidArgumentException('Das Gespräch ist sehr lang geworden. Tippe auf „Neu beginnen“.');
        }
        if (!Claude::available()) {
            throw new InvalidArgumentException(Claude::configured()
                ? 'Das KI-Monatsbudget ist aufgebraucht. Du kannst deine Ziele unter Profil von Hand eintragen.'
                : 'Für den Ziel-Chat wird ein Claude-API-Schlüssel benötigt (config.php).');
        }
        if (!Db::value('SELECT COUNT(*) FROM d3_chat_messages WHERE thread = ?', [self::THREAD])) {
            self::store('assistant', self::greeting(), null);
        }
        self::store('user', $text, null);

        $res = Claude::structured('coach', self::systemPrompt(), [['type' => 'text', 'text' => self::transcript()]], self::schema(), 3000, 'low');
        if (!$res['ok']) {
            Db::run('DELETE FROM d3_chat_messages WHERE thread = ? ORDER BY id DESC LIMIT 1', [self::THREAD]);
            throw new InvalidArgumentException('Der Coach ist gerade nicht erreichbar (' . $res['error'] . '). Versuch es gleich nochmal.');
        }
        $d = $res['data'];
        $proposal = null;
        if (!empty($d['ready']) && !empty($d['goals'])) {
            $proposal = ['goals' => self::cleanGoals((array)$d['goals']), 'constraints' => self::cleanConstraints((array)($d['constraints'] ?? []))];
        }
        self::store('assistant', mb_substr((string)($d['reply'] ?? ''), 0, 2000), $proposal);
        return self::messages();
    }

    private static function store(string $role, string $content, ?array $meta): void
    {
        Db::run('INSERT INTO d3_chat_messages (thread, role, content, meta_json) VALUES (?,?,?,?)',
            [self::THREAD, $role, $content, $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
    }

    private static function transcript(): string
    {
        $lines = [];
        foreach (self::messages() as $m) {
            $who = $m['role'] === 'user' ? 'Nutzer' : 'Coach';
            $lines[] = "$who: " . $m['content'];
            if ($m['proposal']) {
                $lines[] = '(Coach hat diese Ziele vorgeschlagen: ' . json_encode($m['proposal']['goals'], JSON_UNESCAPED_UNICODE) . ')';
            }
        }
        $ctx = [
            'heute' => today(),
            'profil' => array_filter(Settings::profile(), fn($v) => $v !== null && $v !== ''),
            'aktuelle_ziele' => self::forPrompt(),
        ];
        return "Kontext (JSON): " . json_encode($ctx, JSON_UNESCAPED_UNICODE) . "\n\nGespräch bisher:\n" . implode("\n", $lines)
             . "\n\nAntworte jetzt als Coach auf die letzte Nachricht des Nutzers.";
    }

    private static function systemPrompt(): string
    {
        return <<<TXT
Du bist der Coach der persönlichen Fitness-App "dirks3n" und führst ein kurzes Gespräch auf Deutsch (du-Form), um die Trainingsziele des Nutzers zu klären.
Vorgehen:
- Verstehe, was der Nutzer erreichen will. Frag gezielt nach, wenn etwas Wichtiges fehlt: Zeitraum/Termin, aktueller Stand (z. B. aktuelle 5-km-Zeit, Gewicht, Kraftwerte), Verletzungen/Beschwerden, wie viel Zeit pro Tag realistisch ist, Ausstattung (Studio/zu Hause), Sportarten.
- Stelle pro Antwort höchstens 2 Fragen, kurz und freundlich. Keine langen Erklärungen.
- Sobald genug bekannt ist (spätestens nach ein paar Runden – fehlende Details darfst du vernünftig annehmen und das sagen), setze ready = true und fülle goals: messbare Ziele mit Zielwert, Ausgangswert (falls bekannt), Termin (YYYY-MM-DD oder leer) und Priorität (1 = wichtigstes). In reply fasst du die Ziele in 1–2 Sätzen zusammen und bittest um Bestätigung.
- Wenn der Nutzer Ziele ändern will, passe sie an und setze erneut ready = true mit der vollständigen neuen Liste.
- Sei realistisch: Weise freundlich darauf hin, wenn ein Ziel im Zeitraum sehr ehrgeizig ist, und schlage eine Zwischenetappe vor.
- Keine medizinischen Diagnosen. Bei Schmerzen/Verletzungen: in constraints.injuries festhalten und ggf. Arztbesuch empfehlen.
- Solange ready = false: goals = [] und constraints mit leeren Werten.
TXT;
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reply' => ['type' => 'string'],
                'ready' => ['type' => 'boolean'],
                'goals' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string', 'enum' => array_keys(self::CATEGORIES)],
                        'title' => ['type' => 'string'],
                        'metric' => ['type' => 'string'],
                        'target' => ['type' => 'string'],
                        'baseline' => ['type' => 'string'],
                        'deadline' => ['type' => 'string'],
                        'priority' => ['type' => 'integer'],
                        'notes' => ['type' => 'string'],
                    ],
                    'required' => ['category', 'title', 'metric', 'target', 'baseline', 'deadline', 'priority', 'notes'],
                    'additionalProperties' => false,
                ]],
                'constraints' => [
                    'type' => 'object',
                    'properties' => [
                        'minutes_per_day' => ['type' => 'integer'],
                        'injuries' => ['type' => 'string'],
                        'equipment' => ['type' => 'string'],
                        'sports' => ['type' => 'string'],
                        'other' => ['type' => 'string'],
                    ],
                    'required' => ['minutes_per_day', 'injuries', 'equipment', 'sports', 'other'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['reply', 'ready', 'goals', 'constraints'],
            'additionalProperties' => false,
        ];
    }

    private static function cleanGoals(array $goals): array
    {
        $out = [];
        foreach (array_slice($goals, 0, 6) as $g) {
            if (!is_array($g) || trim((string)($g['title'] ?? '')) === '') continue;
            $deadline = (string)($g['deadline'] ?? '');
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $deadline);
            $out[] = [
                'category' => isset(self::CATEGORIES[$g['category'] ?? '']) ? $g['category'] : 'other',
                'title' => mb_substr(trim((string)$g['title']), 0, 160),
                'metric' => mb_substr(trim((string)($g['metric'] ?? '')), 0, 120),
                'target' => mb_substr(trim((string)($g['target'] ?? '')), 0, 80),
                'baseline' => mb_substr(trim((string)($g['baseline'] ?? '')), 0, 80),
                'deadline' => $d && $d->format('Y-m-d') === $deadline ? $deadline : null,
                'priority' => max(1, min(3, (int)($g['priority'] ?? 2))),
                'notes' => mb_substr(trim((string)($g['notes'] ?? '')), 0, 500),
            ];
        }
        usort($out, fn($a, $b) => $a['priority'] <=> $b['priority']);
        return $out;
    }

    private static function cleanConstraints(array $c): array
    {
        return [
            'minutes_per_day' => max(0, min(300, (int)($c['minutes_per_day'] ?? 0))),
            'injuries' => mb_substr(trim((string)($c['injuries'] ?? '')), 0, 300),
            'equipment' => mb_substr(trim((string)($c['equipment'] ?? '')), 0, 200),
            'sports' => mb_substr(trim((string)($c['sports'] ?? '')), 0, 200),
            'other' => mb_substr(trim((string)($c['other'] ?? '')), 0, 300),
        ];
    }

    // ------------------------------------------------------------------ Bestätigen

    /** Vorschlag aus einer Coach-Nachricht übernehmen → ersetzt die bisherigen Ziele */
    public static function confirm(int $messageId): array
    {
        $row = Db::one('SELECT meta_json FROM d3_chat_messages WHERE id = ? AND thread = ? AND role = ?', [$messageId, self::THREAD, 'assistant']);
        $p = $row && $row['meta_json'] ? json_decode($row['meta_json'], true) : null;
        if (!$p || empty($p['goals'])) {
            throw new InvalidArgumentException('Zu dieser Nachricht gibt es keinen Ziel-Vorschlag.');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::run("UPDATE d3_goals SET status = 'archived', archived_at = NOW() WHERE status = 'active'");
            foreach ($p['goals'] as $g) {
                Db::run('INSERT INTO d3_goals (category, title, metric, target, baseline, deadline, priority, notes) VALUES (?,?,?,?,?,?,?,?)',
                    [$g['category'], $g['title'], $g['metric'], $g['target'], $g['baseline'], $g['deadline'], $g['priority'], $g['notes']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        // Rahmenbedingungen ins Profil übernehmen (nur, was der Chat wirklich weiß)
        $c = (array)($p['constraints'] ?? []);
        Settings::set('constraints', $c);
        $profile = Settings::profile();
        $first = $p['goals'][0];
        $profile['goal'] = $first['title'] . ($first['target'] !== '' ? ' (' . $first['target'] . ')' : '');
        if ($first['deadline']) $profile['goal_deadline'] = $first['deadline'];
        if (!empty($c['minutes_per_day'])) $profile['session_max_min'] = (int)$c['minutes_per_day'];
        if (!empty($c['equipment'])) $profile['equipment'] = $c['equipment'];
        if (!empty($c['sports'])) $profile['sports'] = $c['sports'];
        if (!empty($c['injuries']) && !str_contains((string)$profile['notes'], $c['injuries'])) {
            $profile['notes'] = trim($profile['notes'] . "\nBeschwerden: " . $c['injuries']);
        }
        Settings::set('profile', $profile);
        Settings::set('goals_skipped', false);
        self::store('assistant', 'Gespeichert! Ab jetzt plane ich deine Trainings auf diese Ziele hin. Ändern kannst du sie jederzeit hier im Chat.', null);
        return self::active();
    }
}
