<?php
declare(strict_types=1);

/**
 * Aufrufe an die Claude API (Anthropic) per cURL – nur serverseitig.
 * Jeder Aufruf wird mit Kosten protokolliert; die Monatsgrenze wird vorher geprüft.
 */
final class Claude
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private static function endpoint(): string
    {
        return (string)cfg('anthropic_endpoint', self::ENDPOINT); // nur zum Testen änderbar
    }
    private const API_VERSION = '2026-10-21';

    /** Preise in US-Dollar pro 1 Mio. Tokens: [Eingabe, Ausgabe] */
    private const PRICES = [
        'claude-haiku-5-5'  => [0.10, 0.50],
        'claude-sonnet-5-5' => [2.00, 10.00],
        'claude-sonnet-5'   => [2.00, 10.00],
        'claude-opus-5-5'   => [4.00, 20.00],
        'claude-opus-5'     => [5.00, 25.00],
        'claude-opus-4-8'   => [5.00, 25.00],
    ];

    public static function configured(): bool
    {
        return trim((string)cfg('anthropic_api_key', '')) !== '';
    }

    public static function monthSpendUsd(): float
    {
        $start = now()->format('Y-m-01 00:00:00');
        return (float)(Db::value('SELECT COALESCE(SUM(cost_usd), 0) FROM d3_ai_usage WHERE created_at >= ?', [$start]) ?? 0);
    }

    public static function budgetUsd(): float
    {
        return (float)cfg('ai_monthly_budget_usd', 3.0);
    }

    /** Darf gerade ein KI-Aufruf gemacht werden? */
    public static function available(): bool
    {
        return self::configured() && self::monthSpendUsd() < self::budgetUsd();
    }

    public static function model(string $purpose): string
    {
        $models = (array)cfg('models', []);
        return $models[$purpose] ?? 'claude-sonnet-5-5';
    }

    /**
     * Strukturierte Antwort anfordern (JSON nach Schema).
     *
     * @return array{ok:bool, data?:array, error?:string, model?:string}
     */
    public static function structured(string $purpose, string $system, array $userContent, array $schema, int $maxTokens = 4000, string $effort = 'low'): array
    {
        if (!self::available()) {
            return ['ok' => false, 'error' => self::configured() ? 'Monatsbudget erreicht' : 'Kein API-Schlüssel'];
        }
        $model = self::model($purpose);
        $body = [
            'model'         => $model,
            'max_tokens'    => $maxTokens,
            'system'        => $system,
            'messages'      => [['role' => 'user', 'content' => $userContent]],
            'output_config' => [
                'effort' => $effort,
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        ];
        $headers = [];
        // Für Sonnet/Opus: falls ein Sicherheitsfilter fälschlich ablehnt, springt automatisch ein anderes Modell ein
        if (str_contains($model, 'sonnet') || str_contains($model, 'opus')) {
            $body['fallbacks'] = 'default';
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
        }

        $res = self::request($body, $headers);
        if (!$res['ok']) {
            return $res;
        }
        $json = $res['json'];
        self::logUsage($purpose, (string)($json['model'] ?? $model), (array)($json['usage'] ?? []));

        $stop = $json['stop_reason'] ?? '';
        if ($stop === 'refusal') {
            return ['ok' => false, 'error' => 'KI hat die Anfrage abgelehnt'];
        }
        if ($stop === 'max_tokens') {
            return ['ok' => false, 'error' => 'Antwort zu lang (max_tokens)'];
        }
        $text = '';
        foreach ((array)($json['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Antwort war kein gültiges JSON'];
        }
        return ['ok' => true, 'data' => $data, 'model' => (string)($json['model'] ?? $model)];
    }

    /** @return array{ok:bool, json?:array, error?:string} */
    private static function request(array $body, array $extraHeaders): array
    {
        $ch = curl_init(self::endpoint());
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 55, // unter dem PHP-Limit von 60 s bleiben
            CURLOPT_HTTPHEADER     => array_merge([
                'content-type: application/json',
                'x-api-key: ' . cfg('anthropic_api_key'),
                'anthropic-version: ' . self::API_VERSION,
            ], $extraHeaders),
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $attempt = 0;
        do {
            $attempt++;
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $retry = $raw === false || $status === 429 || $status === 529 || $status >= 500;
            if ($retry && $attempt < 2) {
                sleep(2);
            }
        } while ($retry && $attempt < 2);
        $err = curl_error($ch);

        if ($raw === false) {
            app_log("Claude: Verbindungsfehler: $err");
            return ['ok' => false, 'error' => 'Keine Verbindung zur KI'];
        }
        $json = json_decode((string)$raw, true);
        if ($status !== 200 || !is_array($json)) {
            $msg = is_array($json) ? ($json['error']['message'] ?? 'Unbekannter Fehler') : 'Ungültige Antwort';
            app_log("Claude: HTTP $status: $msg");
            return ['ok' => false, 'error' => "KI-Fehler ($status)"];
        }
        return ['ok' => true, 'json' => $json];
    }

    private static function logUsage(string $purpose, string $model, array $usage): void
    {
        $in = (int)($usage['input_tokens'] ?? 0);
        $out = (int)($usage['output_tokens'] ?? 0);
        $cr = (int)($usage['cache_read_input_tokens'] ?? 0);
        $cw = (int)($usage['cache_creation_input_tokens'] ?? 0);
        [$pIn, $pOut] = self::PRICES[$model] ?? [4.00, 20.00]; // unbekannt → teuer schätzen
        $cost = ($in * $pIn + $out * $pOut + $cr * $pIn * 0.1 + $cw * $pIn * 1.25) / 1_000_000;
        Db::run(
            'INSERT INTO d3_ai_usage (purpose, model, input_tokens, output_tokens, cache_read, cache_write, cost_usd) VALUES (?,?,?,?,?,?,?)',
            [$purpose, $model, $in, $out, $cr, $cw, round($cost, 6)]
        );
    }
}
