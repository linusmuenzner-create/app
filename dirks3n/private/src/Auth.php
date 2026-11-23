<?php
declare(strict_types=1);

/** Login, Sitzung und CSRF-Schutz. */
final class Auth
{
    private const MAX_FAILS_PER_IP = 5;     // pro 15 Minuten
    private const MAX_FAILS_GLOBAL = 20;    // pro 15 Minuten (gegen verteilte Angriffe)

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = (int)cfg('session_days', 30) * 86400;
        // Eigener Sitzungsordner: auf Shared Hosting räumen sonst evtl. fremde Einstellungen auf
        session_save_path(D3_PRIVATE . '/sessions');
        ini_set('session.gc_maxlifetime', (string)$lifetime);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('d3sess');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => PHP_SAPI !== 'cli' && (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function userId(): ?int
    {
        self::startSession();
        return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
    }

    public static function requireLogin(): int
    {
        $uid = self::userId();
        if ($uid === null) {
            Http::error('Nicht angemeldet', 401);
        }
        return $uid;
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function requireCsrf(): void
    {
        $given = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!token_equals(self::csrfToken(), $given)) {
            Http::error('Ungültiges Sicherheits-Token. Bitte Seite neu laden.', 403);
        }
    }

    public static function hasUser(): bool
    {
        return (int)Db::value('SELECT COUNT(*) FROM d3_users') > 0;
    }

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }

    public static function createUser(string $username, string $password): void
    {
        Db::run('INSERT INTO d3_users (username, password_hash) VALUES (?, ?)', [$username, self::hashPassword($password)]);
    }

    /** @return string|null Fehlermeldung oder null bei Erfolg */
    public static function login(string $username, string $password): ?string
    {
        self::startSession();
        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . cfg('setup_key'));
        $since = now()->modify('-15 minutes')->format('Y-m-d H:i:s');
        Db::run('DELETE FROM d3_login_attempts WHERE attempted_at < ?', [now()->modify('-1 day')->format('Y-m-d H:i:s')]);
        $ipFails = (int)Db::value('SELECT COUNT(*) FROM d3_login_attempts WHERE ip_hash = ? AND attempted_at >= ?', [$ipHash, $since]);
        $allFails = (int)Db::value('SELECT COUNT(*) FROM d3_login_attempts WHERE attempted_at >= ?', [$since]);
        if ($ipFails >= self::MAX_FAILS_PER_IP || $allFails >= self::MAX_FAILS_GLOBAL) {
            return 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
        }

        $user = Db::one('SELECT id, password_hash FROM d3_users WHERE username = ?', [$username]);
        // Auch bei unbekanntem Benutzer einen Hash prüfen, damit die Antwortzeit gleich bleibt
        $hash = $user['password_hash'] ?? password_hash('dummy-password', PASSWORD_BCRYPT);
        if (!password_verify($password, $hash) || $user === null) {
            Db::run('INSERT INTO d3_login_attempts (ip_hash, attempted_at) VALUES (?, ?)', [$ipHash, now()->format('Y-m-d H:i:s')]);
            usleep(300000);
            return 'Benutzername oder Passwort falsch.';
        }
        if (password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            Db::run('UPDATE d3_users SET password_hash = ? WHERE id = ?', [self::hashPassword($password), $user['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return null;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
