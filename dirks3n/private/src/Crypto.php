<?php
declare(strict_types=1);

/**
 * Verschlüsselt Geheimnisse (z. B. Google-Tokens), bevor sie in die Datenbank kommen.
 * Der Schlüssel steht nur in private/config.php ('encryption_key', 64 Hex-Zeichen).
 * Wer nur die Datenbank sieht (z. B. ein Backup), kann die Tokens nicht benutzen.
 */
final class Crypto
{
    private static function key(): string
    {
        $hex = (string)cfg('encryption_key', '');
        if (!preg_match('/^[0-9a-f]{64}$/i', $hex)) {
            throw new RuntimeException('encryption_key in config.php fehlt oder ist ungültig (64 Hex-Zeichen nötig).');
        }
        return hex2bin($hex);
    }

    public static function configured(): bool
    {
        return (bool)preg_match('/^[0-9a-f]{64}$/i', (string)cfg('encryption_key', ''));
    }

    public static function encrypt(string $plain): string
    {
        $key = self::key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'g1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $key = self::key();
        [$ver, $b64] = explode(':', $stored, 2) + [1 => ''];
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            return null;
        }
        if ($ver === 's1' && function_exists('sodium_crypto_secretbox_open')) {
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
            return $plain === false ? null : $plain;
        }
        if ($ver === 'g1') {
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            return $plain === false ? null : $plain;
        }
        return null;
    }
}
