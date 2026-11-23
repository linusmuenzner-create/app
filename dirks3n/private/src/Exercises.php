<?php
declare(strict_types=1);

/**
 * Übungsbibliothek (Free Exercise DB, gemeinfrei) + deutsche Kernauswahl.
 * Die Bibliothek wird beim ersten Zugriff automatisch aus private/data/exercises.json in die Datenbank geladen.
 */
final class Exercises
{
    /**
     * Kernauswahl: id => [deutscher Name, Gruppe, Mehrgelenk?, [Wdh. min, max], Steigerung kg, Pause s, Körpergewicht?]
     * Gruppen: push, pull, legs, core
     */
    public const CATALOG = [
        // Beine
        'Barbell_Squat'                       => ['Kniebeuge (Langhantel)', 'legs', true, [5, 8], 2.5, 180, false],
        'Front_Barbell_Squat'                 => ['Frontkniebeuge', 'legs', true, [5, 8], 2.5, 180, false],
        'Goblet_Squat'                        => ['Goblet Squat', 'legs', true, [8, 12], 2.0, 120, false],
        'Leg_Press'                           => ['Beinpresse', 'legs', true, [8, 12], 5.0, 150, false],
        'Hack_Squat'                          => ['Hackenschmidt-Kniebeuge', 'legs', true, [8, 12], 5.0, 150, false],
        'Smith_Machine_Squat'                 => ['Kniebeuge (Multipresse)', 'legs', true, [8, 10], 2.5, 150, false],
        'Romanian_Deadlift'                   => ['Rumänisches Kreuzheben', 'legs', true, [6, 10], 2.5, 150, false],
        'Barbell_Deadlift'                    => ['Kreuzheben', 'legs', true, [3, 6], 5.0, 180, false],
        'Dumbbell_Lunges'                     => ['Ausfallschritte (Kurzhantel)', 'legs', true, [8, 12], 2.0, 120, false],
        'Split_Squat_with_Dumbbells'          => ['Split Squat (Kurzhantel)', 'legs', true, [8, 12], 2.0, 120, false],
        'Barbell_Hip_Thrust'                  => ['Hip Thrust', 'legs', true, [8, 12], 5.0, 120, false],
        'Leg_Extensions'                      => ['Beinstrecker', 'legs', false, [10, 15], 2.5, 90, false],
        'Lying_Leg_Curls'                     => ['Beinbeuger liegend', 'legs', false, [10, 15], 2.5, 90, false],
        'Glute_Ham_Raise'                     => ['Glute Ham Raise', 'legs', false, [6, 12], 0, 90, true],
        'Good_Morning'                        => ['Good Morning', 'legs', true, [8, 12], 2.5, 120, false],
        'Standing_Calf_Raises'                => ['Wadenheben stehend', 'legs', false, [10, 15], 2.5, 60, false],
        'Seated_Calf_Raise'                   => ['Wadenheben sitzend', 'legs', false, [12, 20], 2.5, 60, false],
        // Drücken
        'Barbell_Bench_Press_-_Medium_Grip'   => ['Bankdrücken', 'push', true, [5, 8], 2.5, 180, false],
        'Barbell_Incline_Bench_Press_-_Medium_Grip' => ['Schrägbankdrücken (Langhantel)', 'push', true, [6, 10], 2.5, 150, false],
        'Dumbbell_Bench_Press'                => ['Bankdrücken (Kurzhantel)', 'push', true, [8, 12], 2.0, 150, false],
        'Incline_Dumbbell_Press'              => ['Schrägbankdrücken (Kurzhantel)', 'push', true, [8, 12], 2.0, 150, false],
        'Close-Grip_Barbell_Bench_Press'      => ['Enges Bankdrücken', 'push', true, [6, 10], 2.5, 150, false],
        'Standing_Military_Press'             => ['Schulterdrücken stehend', 'push', true, [5, 8], 2.5, 150, false],
        'Seated_Dumbbell_Press'               => ['Schulterdrücken (Kurzhantel)', 'push', true, [8, 12], 2.0, 120, false],
        'Arnold_Dumbbell_Press'               => ['Arnold Press', 'push', true, [8, 12], 2.0, 120, false],
        'Pushups'                             => ['Liegestütze', 'push', true, [8, 20], 0, 90, true],
        'Dips_-_Triceps_Version'              => ['Dips', 'push', true, [6, 12], 0, 120, true],
        'Butterfly'                           => ['Butterfly (Maschine)', 'push', false, [10, 15], 2.5, 90, false],
        'Cable_Crossover'                     => ['Kabelzug-Flys', 'push', false, [10, 15], 2.5, 90, false],
        'Side_Lateral_Raise'                  => ['Seitheben', 'push', false, [12, 15], 1.0, 75, false],
        'Triceps_Pushdown'                    => ['Trizepsdrücken am Kabel', 'push', false, [10, 15], 2.5, 75, false],
        'EZ-Bar_Skullcrusher'                 => ['French Press (SZ-Stange)', 'push', false, [8, 12], 2.5, 90, false],
        // Ziehen
        'Pullups'                             => ['Klimmzüge', 'pull', true, [5, 10], 0, 150, true],
        'Chin-Up'                             => ['Klimmzüge (Untergriff)', 'pull', true, [5, 10], 0, 150, true],
        'Wide-Grip_Lat_Pulldown'              => ['Latzug breit', 'pull', true, [8, 12], 2.5, 120, false],
        'Bent_Over_Barbell_Row'               => ['Langhantelrudern', 'pull', true, [6, 10], 2.5, 150, false],
        'Seated_Cable_Rows'                   => ['Rudern am Kabel', 'pull', true, [8, 12], 2.5, 120, false],
        'One-Arm_Dumbbell_Row'                => ['Einarmiges Kurzhantelrudern', 'pull', true, [8, 12], 2.0, 90, false],
        'T-Bar_Row_with_Handle'               => ['T-Bar-Rudern', 'pull', true, [6, 10], 2.5, 150, false],
        'Face_Pull'                           => ['Face Pulls', 'pull', false, [12, 15], 2.5, 75, false],
        'Reverse_Flyes'                       => ['Reverse Flys', 'pull', false, [12, 15], 1.0, 75, false],
        'Barbell_Shrug'                       => ['Shrugs (Langhantel)', 'pull', false, [10, 15], 2.5, 75, false],
        'Barbell_Curl'                        => ['Bizepscurls (Langhantel)', 'pull', false, [8, 12], 2.5, 75, false],
        'Dumbbell_Bicep_Curl'                 => ['Bizepscurls (Kurzhantel)', 'pull', false, [10, 12], 1.0, 75, false],
        'Hammer_Curls'                        => ['Hammercurls', 'pull', false, [10, 12], 1.0, 75, false],
        'Preacher_Curl'                       => ['Scott-Curls', 'pull', false, [8, 12], 2.5, 75, false],
        // Rumpf
        'Plank'                               => ['Unterarmstütz (Plank)', 'core', false, [30, 60], 0, 60, true],
        'Crunches'                            => ['Crunches', 'core', false, [15, 25], 0, 60, true],
        'Hanging_Leg_Raise'                   => ['Beinheben hängend', 'core', false, [8, 15], 0, 75, true],
        'Ab_Roller'                           => ['Bauchroller', 'core', false, [8, 15], 0, 75, true],
        'Russian_Twist'                       => ['Russian Twist', 'core', false, [15, 25], 0, 60, true],
        'Mountain_Climbers'                   => ['Mountain Climbers', 'core', false, [20, 40], 0, 60, true],
    ];

    /** Reihenfolge = Reihenfolge der Filter in der App (häufigste zuerst) */
    public const MUSCLES_DE = [
        'chest' => 'Brust', 'lats' => 'Latissimus', 'middle back' => 'Mittlerer Rücken', 'shoulders' => 'Schultern',
        'quadriceps' => 'Oberschenkel', 'hamstrings' => 'Beinbeuger', 'glutes' => 'Gesäß', 'biceps' => 'Bizeps',
        'triceps' => 'Trizeps', 'abdominals' => 'Bauch', 'calves' => 'Waden', 'lower back' => 'Unterer Rücken',
        'traps' => 'Trapez', 'forearms' => 'Unterarme', 'adductors' => 'Adduktoren', 'abductors' => 'Abduktoren', 'neck' => 'Nacken',
    ];

    public const EQUIPMENT_DE = [
        'barbell' => 'Langhantel', 'dumbbell' => 'Kurzhantel', 'body only' => 'Körpergewicht', 'cable' => 'Kabelzug',
        'machine' => 'Maschine', 'kettlebells' => 'Kettlebell', 'bands' => 'Bänder', 'medicine ball' => 'Medizinball',
        'exercise ball' => 'Gymnastikball', 'foam roll' => 'Faszienrolle', 'e-z curl bar' => 'SZ-Stange', 'other' => 'Sonstiges', '' => '–',
    ];

    public static function available(): bool
    {
        return Aggregator::hasTable('d3_exercises');
    }

    /** Bibliothek beim ersten Zugriff einlesen */
    public static function ensureImported(): void
    {
        if ((int)Db::value('SELECT COUNT(*) FROM d3_exercises') > 0) {
            return;
        }
        $file = D3_PRIVATE . '/data/exercises.json';
        $list = json_decode((string)@file_get_contents($file), true);
        if (!is_array($list)) {
            throw new RuntimeException('private/data/exercises.json fehlt oder ist beschädigt.');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        $st = $pdo->prepare('INSERT IGNORE INTO d3_exercises (id, name, name_de, category, equipment, level, mechanic, primary_muscles, secondary_muscles, instructions, image_count, curated)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($list as $e) {
            if (!isset($e['id'], $e['name']) || !preg_match('/^[A-Za-z0-9_,()\-]+$/', (string)$e['id'])) {
                continue;
            }
            $cat = self::CATALOG[$e['id']] ?? null;
            $st->execute([
                $e['id'], mb_substr((string)$e['name'], 0, 160), $cat[0] ?? null,
                (string)($e['category'] ?? ''), (string)($e['equipment'] ?? ''), (string)($e['level'] ?? ''), (string)($e['mechanic'] ?? ''),
                implode(',', (array)($e['primaryMuscles'] ?? [])), implode(',', (array)($e['secondaryMuscles'] ?? [])),
                json_encode(array_values((array)($e['instructions'] ?? [])), JSON_UNESCAPED_UNICODE),
                min(9, count((array)($e['images'] ?? []))), $cat ? 1 : 0,
            ]);
        }
        $pdo->commit();
    }

    public static function meta(string $id): array
    {
        $c = self::CATALOG[$id] ?? null;
        return [
            'group' => $c[1] ?? null,
            'compound' => $c[2] ?? null,
            'reps' => $c[3] ?? [8, 12],
            'increment' => $c[4] ?? 2.5,
            'rest' => $c[5] ?? 90,
            'bodyweight' => $c[6] ?? false,
        ];
    }

    /** Eine Übung für die App aufbereiten */
    public static function present(array $r, bool $full = false): array
    {
        $prim = array_values(array_filter(explode(',', (string)$r['primary_muscles'])));
        $sec = array_values(array_filter(explode(',', (string)$r['secondary_muscles'])));
        $meta = self::meta($r['id']);
        if (!isset(self::CATALOG[$r['id']])) {
            $meta['bodyweight'] = $r['equipment'] === 'body only';
            $meta['compound'] = $r['mechanic'] === 'compound';
            $meta['rest'] = $r['mechanic'] === 'compound' ? 120 : 75;
            $meta['increment'] = $r['equipment'] === 'dumbbell' ? 2.0 : ($r['mechanic'] === 'compound' ? 2.5 : 1.0);
        }
        $out = [
            'id' => $r['id'],
            'name' => $r['name_de'] ?: $r['name'],
            'name_en' => $r['name'],
            'muscles' => array_map(fn($m) => self::MUSCLES_DE[$m] ?? $m, $prim),
            'equipment' => self::EQUIPMENT_DE[$r['equipment']] ?? $r['equipment'],
            'images' => (int)$r['image_count'],
            'curated' => (bool)$r['curated'],
            'preference' => (int)($r['preference'] ?? 0),
            'meta' => $meta,
        ];
        if ($full) {
            $out['muscles_secondary'] = array_map(fn($m) => self::MUSCLES_DE[$m] ?? $m, $sec);
            $out['level'] = ['beginner' => 'Einsteiger', 'intermediate' => 'Fortgeschritten', 'expert' => 'Profi'][$r['level']] ?? $r['level'];
            $out['category'] = $r['category'];
            $de = $r['instructions_de'] ? json_decode((string)$r['instructions_de'], true) : null;
            $out['instructions'] = $de ?: (json_decode((string)$r['instructions'], true) ?: []);
            $out['instructions_lang'] = $de ? 'de' : 'en';
        }
        return $out;
    }

    public static function get(string $id): ?array
    {
        self::ensureImported();
        return Db::one('SELECT * FROM d3_exercises WHERE id = ?', [$id]);
    }

    public static function search(string $q, string $muscle = '', bool $curatedOnly = false, int $limit = 60): array
    {
        self::ensureImported();
        $where = ["category <> 'stretching'"];
        $params = [];
        if ($q !== '') {
            $where[] = '(name LIKE ? OR name_de LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if ($muscle !== '') {
            $key = array_search($muscle, self::MUSCLES_DE, true) ?: $muscle;
            $where[] = 'FIND_IN_SET(?, primary_muscles)';
            $params[] = $key;
        }
        if ($curatedOnly) {
            $where[] = 'curated = 1';
        }
        $sql = 'SELECT * FROM d3_exercises WHERE ' . implode(' AND ', $where)
             . ' ORDER BY curated DESC, preference DESC, (image_count > 0) DESC, COALESCE(name_de, name) LIMIT ' . max(1, min(200, $limit));
        return array_map(fn($r) => self::present($r), Db::all($sql, $params));
    }

    /** Deutschen Namen → Übungs-ID (für Regel-Pläne und freie KI-Texte) */
    public static function idByGermanName(string $name): ?string
    {
        $n = mb_strtolower(trim($name));
        foreach (self::CATALOG as $id => $c) {
            if (mb_strtolower($c[0]) === $n) {
                return $id;
            }
        }
        foreach (self::CATALOG as $id => $c) {
            $base = mb_strtolower(preg_replace('/\s*\(.*\)$/u', '', $c[0]));
            if ($base !== '' && str_contains($n, $base)) {
                return $id;
            }
        }
        return null;
    }

    /** Anleitung einmalig auf Deutsch übersetzen (sehr günstiges Modell, Ergebnis wird gespeichert) */
    public static function translateInstructions(string $id): bool
    {
        $r = self::get($id);
        if (!$r || $r['instructions_de'] || !Claude::available()) {
            return false;
        }
        $steps = json_decode((string)$r['instructions'], true) ?: [];
        if (!$steps) {
            return false;
        }
        $res = Claude::structured(
            'translate',
            'Übersetze Übungsanleitungen aus dem Fitnessstudio ins Deutsche (du-Form, kurz, klar). Behalte die Anzahl der Schritte bei.',
            [['type' => 'text', 'text' => json_encode(['exercise' => $r['name'], 'steps' => $steps], JSON_UNESCAPED_UNICODE)]],
            ['type' => 'object', 'properties' => ['name_de' => ['type' => 'string'], 'steps' => ['type' => 'array', 'items' => ['type' => 'string']]],
             'required' => ['name_de', 'steps'], 'additionalProperties' => false],
            2000,
            'low'
        );
        if (!$res['ok'] || empty($res['data']['steps'])) {
            return false;
        }
        Db::run('UPDATE d3_exercises SET instructions_de = ?, name_de = COALESCE(name_de, ?) WHERE id = ?', [
            json_encode(array_map(fn($s) => mb_substr((string)$s, 0, 600), $res['data']['steps']), JSON_UNESCAPED_UNICODE),
            mb_substr((string)$res['data']['name_de'], 0, 160) ?: null,
            $id,
        ]);
        return true;
    }

    public static function imageCachePath(string $id, int $n): string
    {
        return D3_PRIVATE . '/cache/exercises/' . md5($id) . '_' . $n . '.jpg';
    }

    /** Bild einmalig von GitHub holen, verkleinern und speichern. true = liegt im Cache */
    public static function cacheImage(string $id, int $n): bool
    {
        $file = self::imageCachePath($id, $n);
        if (is_file($file)) {
            return true;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $ch = curl_init(self::imageSourceUrl($id, $n));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'dirks3n-exercise-cache/1.0',
        ]);
        $data = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($data === false || $status !== 200 || strlen((string)$data) < 500 || substr((string)$data, 0, 3) !== "\xFF\xD8\xFF") {
            app_log("Übungsbild nicht ladbar: $id/$n (HTTP $status)");
            return false;
        }
        // Etwas verkleinern, falls GD verfügbar (spart Speicher & Ladezeit)
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring((string)$data))) {
            $w = imagesx($img);
            if ($w > 600) {
                $h = (int)round(imagesy($img) * 600 / $w);
                $small = imagecreatetruecolor(600, $h);
                imagecopyresampled($small, $img, 0, 0, 0, 0, 600, $h, $w, imagesy($img));
                ob_start();
                imagejpeg($small, null, 80);
                $data = (string)ob_get_clean();
            }
        }
        return file_put_contents($file, $data, LOCK_EX) !== false;
    }

    /** Für den Cronjob: Bilder der Kernübungen nach und nach vorab laden */
    public static function prefetchImages(int $max = 30): int
    {
        self::ensureImported();
        $done = 0;
        foreach (Db::all('SELECT id, image_count FROM d3_exercises WHERE curated = 1 OR preference > 0') as $r) {
            for ($n = 0; $n < min(2, (int)$r['image_count']); $n++) {
                if (is_file(self::imageCachePath($r['id'], $n))) continue;
                if ($done >= $max) return $done;
                self::cacheImage($r['id'], $n);
                $done++;
            }
        }
        return $done;
    }

    /** Bild-Adresse im Original-Repository (nur für den Bild-Cache) */
    public static function imageSourceUrl(string $id, int $n): string
    {
        return 'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/' . rawurlencode($id) . '/' . $n . '.jpg';
    }
}
