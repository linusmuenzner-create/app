<?php
declare(strict_types=1);

/** Fehler, bei dem du dich neu mit Google verbinden musst (z. B. Zustimmung abgelaufen). */
final class GoogleReauthNeeded extends RuntimeException
{
}

/**
 * Google-Login (OAuth 2.0 mit PKCE) und Token-Verwaltung.
 * - Tokens liegen verschlüsselt in d3_oauth_tokens, nie im Browser.
 * - Abgelaufene Zugriffstokens werden automatisch mit dem Refresh-Token erneuert.
 * - Wurde die Zustimmung widerrufen oder ist sie abgelaufen (Testmodus: 7 Tage),
 *   wird der Status auf "reauth" gesetzt und die App zeigt "Neu verbinden".
 */
final class GoogleAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** Nur Lese-Rechte – dirks3n schreibt nichts in dein Google-Konto */
    public const SCOPES = [
        'https://www.googleapis.com/auth/googlehealth.sleep.readonly',
        'https://www.googleapis.com/auth/googlehealth.health_metrics_and_measurements.readonly',
        'https://www.googleapis.com/auth/googlehealth.activity_and_fitness.readonly',
        'https://www.googleapis.com/auth/googlehealth.settings.readonly', // Gerät (Akku, letzte Synchronisation)
    ];

    public static function configured(): bool
    {
        $g = (array)cfg('google', []);
        return !empty($g['client_id']) && !empty($g['client_secret']) && Crypto::configured();
    }

    /** Was fehlt in config.php? (für die Anzeige in der App – nie die Werte selbst) */
    public static function configIssues(): array
    {
        $issues = [];
        $g = cfg('google', null);
        if (!is_array($g)) {
            return ["Der Abschnitt 'google' => [ … ] fehlt in config.php (Vorlage: config.sample.php)."];
        }
        $id = trim((string)($g['client_id'] ?? ''));
        $secret = trim((string)($g['client_secret'] ?? ''));
        if ($id === '') {
            $issues[] = 'client_id ist leer.';
        } elseif (!str_ends_with($id, '.apps.googleusercontent.com')) {
            $issues[] = 'client_id sieht falsch aus – sie muss auf .apps.googleusercontent.com enden.';
        }
        if ($secret === '') {
            $issues[] = 'client_secret ist leer.';
        }
        $key = (string)cfg('encryption_key', '');
        if ($key === '') {
            $issues[] = "encryption_key fehlt oder ist leer (64 Zeichen aus 0–9 und a–f, z. B. mit 'openssl rand -hex 32').";
        } elseif (!preg_match('/^[0-9a-f]{64}$/i', $key)) {
            $issues[] = 'encryption_key ist ungültig: ' . strlen($key) . ' Zeichen – nötig sind genau 64 Zeichen, nur 0–9 und a–f (keine Leerzeichen).';
        }
        $redirect = self::redirectUri();
        if (!str_starts_with($redirect, 'https://') && !str_contains($redirect, 'localhost')) {
            $issues[] = 'redirect_uri muss mit https:// beginnen.';
        }
        return $issues;
    }

    private static function c(string $k): string
    {
        $g = (array)cfg('google', []);
        return (string)($g[$k] ?? '');
    }

    public static function redirectUri(): string
    {
        return self::c('redirect_uri') ?: rtrim((string)cfg('base_url'), '/') . '/google-callback.php';
    }

    private static function tokenUrl(): string
    {
        return self::c('token_url') ?: self::TOKEN_URL; // nur zum Testen änderbar
    }

    /** Schritt 1: Adresse, zu der wir den Browser schicken */
    public static function authorizationUrl(): string
    {
        Db::run('DELETE FROM d3_oauth_states WHERE created_at < ?', [now()->modify('-10 minutes')->format('Y-m-d H:i:s')]);
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        Db::run('INSERT INTO d3_oauth_states (state, code_verifier, created_at) VALUES (?,?,?)', [$state, $verifier, now()->format('Y-m-d H:i:s')]);
        return (self::c('auth_url') ?: self::AUTH_URL) . '?' . http_build_query([
            'client_id'             => self::c('client_id'),
            'redirect_uri'          => self::redirectUri(),
            'response_type'         => 'code',
            'scope'                 => implode(' ', self::SCOPES),
            'access_type'           => 'offline',  // Refresh-Token für den Cronjob
            'prompt'                => 'consent',  // sorgt dafür, dass Google immer einen Refresh-Token schickt
            'include_granted_scopes' => 'true',
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Schritt 2: Google schickt den Browser mit ?code=…&state=… zurück */
    public static function handleCallback(string $state, string $code): void
    {
        $row = Db::one('SELECT code_verifier, created_at FROM d3_oauth_states WHERE state = ?', [$state]);
        Db::run('DELETE FROM d3_oauth_states WHERE state = ?', [$state]); // nur einmal verwendbar
        if (!$row || strtotime($row['created_at']) < time() - 600) {
            throw new RuntimeException('Der Anmeldevorgang ist abgelaufen oder ungültig. Bitte in dirks3n erneut auf „Mit Google verbinden“ tippen.');
        }
        $tok = self::tokenRequest([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::redirectUri(),
            'code_verifier' => $row['code_verifier'],
        ]);
        if (empty($tok['refresh_token'])) {
            throw new RuntimeException('Google hat keinen Refresh-Token geschickt. Bitte unter myaccount.google.com/permissions den Zugriff für dirks3n entfernen und neu verbinden.');
        }
        $granted = explode(' ', (string)($tok['scope'] ?? ''));
        $missing = array_diff(self::SCOPES, $granted);
        self::store($tok, (string)($tok['refresh_token']));
        if ($missing) {
            $names = array_map(fn($s) => str_replace('https://www.googleapis.com/auth/googlehealth.', '', $s), $missing);
            Db::run("UPDATE d3_oauth_tokens SET last_error = ? WHERE provider = 'google'", ['Nicht freigegeben: ' . implode(', ', $names)]);
        }
        // Konto-ID merken (zur Kontrolle, welches Konto verbunden ist)
        try {
            $id = GoogleHealth::get('users/me/identity');
            Db::run("UPDATE d3_oauth_tokens SET account_id = ? WHERE provider = 'google'", [(string)($id['healthUserId'] ?? '')]);
        } catch (Throwable $e) {
            app_log('Google: Identity nicht abrufbar: ' . $e->getMessage());
        }
        // Nachladen von vorne beginnen
        Db::run('DELETE FROM d3_google_sync');
    }

    private static function store(array $tok, ?string $refreshToken): void
    {
        $expires = now()->modify('+' . max(60, (int)($tok['expires_in'] ?? 3600) - 60) . ' seconds')->format('Y-m-d H:i:s');
        $params = [
            Crypto::encrypt((string)$tok['access_token']),
            $refreshToken !== null ? Crypto::encrypt($refreshToken) : null,
            $expires,
            (string)($tok['scope'] ?? ''),
        ];
        Db::run(
            "INSERT INTO d3_oauth_tokens (provider, access_token, refresh_token, expires_at, scopes, status, last_error, connected_at)
             VALUES ('google', ?, ?, ?, ?, 'connected', '', NOW())
             ON DUPLICATE KEY UPDATE access_token = VALUES(access_token),
               refresh_token = COALESCE(VALUES(refresh_token), refresh_token),
               expires_at = VALUES(expires_at), scopes = IF(VALUES(scopes) = '', scopes, VALUES(scopes)),
               status = 'connected', last_error = '',
               connected_at = IF(VALUES(refresh_token) IS NULL, connected_at, NOW())",
            $params
        );
    }

    public static function status(): ?array
    {
        $row = Db::one("SELECT expires_at, scopes, account_id, status, last_error, connected_at, updated_at FROM d3_oauth_tokens WHERE provider = 'google'");
        return $row ?: null;
    }

    public static function isConnected(): bool
    {
        $s = self::status();
        return $s !== null && $s['status'] === 'connected';
    }

    /** Gültiges Zugriffstoken holen (erneuert es bei Bedarf automatisch) */
    public static function accessToken(bool $forceRefresh = false): string
    {
        $row = Db::one("SELECT access_token, refresh_token, expires_at, status FROM d3_oauth_tokens WHERE provider = 'google'");
        if (!$row) {
            throw new GoogleReauthNeeded('Nicht mit Google verbunden.');
        }
        if ($row['status'] === 'reauth') {
            throw new GoogleReauthNeeded('Google-Verbindung muss erneuert werden.');
        }
        if (!$forceRefresh && $row['expires_at'] && strtotime($row['expires_at']) > time() + 120) {
            $at = Crypto::decrypt($row['access_token']);
            if ($at) {
                return $at;
            }
        }
        $refresh = Crypto::decrypt($row['refresh_token']);
        if (!$refresh) {
            self::markReauth('Refresh-Token fehlt oder ist nicht lesbar (encryption_key geändert?).');
            throw new GoogleReauthNeeded('Google-Verbindung muss erneuert werden.');
        }
        try {
            $tok = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        } catch (GoogleReauthNeeded $e) {
            self::markReauth($e->getMessage());
            throw $e;
        }
        // Google schickt manchmal einen neuen Refresh-Token mit – dann diesen speichern
        self::store($tok, $tok['refresh_token'] ?? null);
        return (string)$tok['access_token'];
    }

    public static function markReauth(string $why): void
    {
        Db::run("UPDATE d3_oauth_tokens SET status = 'reauth', last_error = ? WHERE provider = 'google'", [mb_substr($why, 0, 500)]);
        app_log('Google: Neu verbinden nötig – ' . $why);
    }

    public static function disconnect(): void
    {
        $row = Db::one("SELECT refresh_token FROM d3_oauth_tokens WHERE provider = 'google'");
        $refresh = $row ? Crypto::decrypt($row['refresh_token']) : null;
        if ($refresh) {
            // Zugriff auch bei Google widerrufen (Fehler hier sind egal)
            $ch = curl_init(self::REVOKE_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_POSTFIELDS => http_build_query(['token' => $refresh]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            curl_exec($ch);
        }
        Db::run("DELETE FROM d3_oauth_tokens WHERE provider = 'google'");
        Db::run('DELETE FROM d3_google_sync');
    }

    private static function tokenRequest(array $fields): array
    {
        $fields += ['client_id' => self::c('client_id'), 'client_secret' => self::c('client_secret')];
        $ch = curl_init(self::tokenUrl());
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            throw new RuntimeException('Google nicht erreichbar: ' . curl_error($ch));
        }
        $json = json_decode((string)$raw, true) ?: [];
        if ($status !== 200 || empty($json['access_token'])) {
            $err = (string)($json['error'] ?? "HTTP $status");
            $desc = (string)($json['error_description'] ?? '');
            // invalid_grant = Refresh-Token abgelaufen/widerrufen → neu verbinden
            if ($err === 'invalid_grant' || $err === 'unauthorized_client') {
                throw new GoogleReauthNeeded(trim("Google: $err $desc"));
            }
            throw new RuntimeException(trim("Google-Token-Fehler: $err $desc"));
        }
        return $json;
    }
}
