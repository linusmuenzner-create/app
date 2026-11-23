<?php
declare(strict_types=1);

/** Hilfen für Anfragen und JSON-Antworten. */
final class Http
{
    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');
        header('Cache-Control: no-store');
    }

    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }

    public static function error(string $message, int $status = 400): void
    {
        self::json(['error' => $message], $status);
    }

    /** JSON-Body der Anfrage lesen */
    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') {
            return $_POST;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function requireHttps(): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443');
        if (!$https && PHP_SAPI !== 'cli' && !self::isLocalhost()) {
            self::error('HTTPS erforderlich', 403);
        }
    }

    private static function isLocalhost(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');
    }

    /** Token aus Header "Authorization: Bearer …", "X-Token" oder ?token= lesen */
    public static function bearerToken(): ?string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        if (!empty($_SERVER['HTTP_X_TOKEN'])) {
            return trim($_SERVER['HTTP_X_TOKEN']);
        }
        return isset($_GET['token']) ? (string)$_GET['token'] : null;
    }

    /** Antwort sofort an den Client schicken und danach weiterarbeiten (falls PHP-FPM) */
    public static function finishEarly(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
    }
}
