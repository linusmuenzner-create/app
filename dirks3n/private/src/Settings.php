<?php
declare(strict_types=1);

/** Einstellungen und Profil, gespeichert als JSON in d3_settings. */
final class Settings
{
    public const DEFAULT_PROFILE = [
        'name'            => '',
        'goal'            => 'Muskelaufbau und Fitness verbessern',
        'goal_deadline'   => '',
        'experience'      => 'Fortgeschrittener Anfänger',
        'sports'          => 'Krafttraining',
        'equipment'       => 'Fitnessstudio',
        'sleep_target_h'  => 7.5,
        'session_max_min' => 75,
        'notes'           => '',
        'birth_year'      => null,
        'sex'             => '',
        'height_cm'       => null,
    ];

    public static function get(string $key, $default = null)
    {
        $v = Db::value('SELECT v FROM d3_settings WHERE k = ?', [$key]);
        if ($v === null) {
            return $default;
        }
        $decoded = json_decode((string)$v, true);
        return $decoded ?? $default;
    }

    public static function set(string $key, $value): void
    {
        Db::run(
            'INSERT INTO d3_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE)]
        );
    }

    public static function profile(): array
    {
        return array_merge(self::DEFAULT_PROFILE, (array)self::get('profile', []));
    }

    /** @return string[] */
    public static function calendarUrls(): array
    {
        return array_values(array_filter((array)self::get('calendar_urls', []), 'is_string'));
    }
}
