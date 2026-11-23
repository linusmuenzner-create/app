<?php
declare(strict_types=1);

/**
 * Bereitschafts-Score (0–100) – wird ohne KI berechnet, damit die KI nur noch
 * fertige Zahlen bekommt (spart Kosten) und es auch ohne KI funktioniert.
 */
final class Readiness
{
    private const WEIGHTS = ['hrv' => 0.35, 'sleep' => 0.30, 'rhr' => 0.20, 'load' => 0.15];

    public static function forDay(string $day): array
    {
        $d = new DateTimeImmutable($day);
        $from28 = $d->modify('-28 days')->format('Y-m-d');
        $yesterday = $d->modify('-1 day')->format('Y-m-d');
        $s = Aggregator::series($from28, $day, ['hrv', 'resting_hr', 'sleep_min', 'sleep_deep_min', 'sleep_rem_min', 'training_load', 'training_min', 'strength_min', 'steps']);
        $profile = Settings::profile();

        $components = [];
        $facts = [];

        // --- HRV: heute vs. Schnitt der letzten 7 Tage
        $hrvToday = $s['hrv'][$day] ?? null;
        $hrvBase = self::avgBetween($s['hrv'], $d->modify('-7 days')->format('Y-m-d'), $yesterday);
        if ($hrvToday !== null && $hrvBase !== null) {
            $ratio = $hrvToday / max(1, $hrvBase);
            $components['hrv'] = self::clamp(50 + ($ratio - 1) * 250);
            $facts['hrv'] = ['today' => round($hrvToday), 'baseline7' => round($hrvBase), 'change_pct' => round(($ratio - 1) * 100)];
        } elseif ($hrvToday !== null) {
            $facts['hrv'] = ['today' => round($hrvToday), 'baseline7' => null, 'note' => 'zu wenig Verlauf'];
        }

        // --- Ruhepuls: heute vs. Schnitt 7 Tage (höher = schlechter)
        $rhrToday = $s['resting_hr'][$day] ?? ($s['resting_hr'][$yesterday] ?? null);
        $rhrBase = self::avgBetween($s['resting_hr'], $d->modify('-8 days')->format('Y-m-d'), $d->modify('-1 day')->format('Y-m-d'));
        if ($rhrToday !== null && $rhrBase !== null) {
            $diff = $rhrToday - $rhrBase;
            $components['rhr'] = self::clamp(70 - $diff * 10);
            $facts['resting_hr'] = ['today' => round($rhrToday), 'baseline7' => round($rhrBase), 'diff_bpm' => round($diff, 1)];
        } elseif ($rhrToday !== null) {
            $facts['resting_hr'] = ['today' => round($rhrToday), 'baseline7' => null];
        }

        // --- Schlaf letzte Nacht vs. Ziel
        $sleepMin = $s['sleep_min'][$day] ?? null;
        $target = (float)($profile['sleep_target_h'] ?: 7.5) * 60;
        if ($sleepMin !== null) {
            $components['sleep'] = self::clamp($sleepMin / $target * 100);
            $facts['sleep'] = [
                'hours' => round($sleepMin / 60, 1),
                'target_h' => round($target / 60, 1),
                'deep_min' => (int)($s['sleep_deep_min'][$day] ?? 0),
                'rem_min' => (int)($s['sleep_rem_min'][$day] ?? 0),
                'avg7_h' => ($a = self::avgBetween($s['sleep_min'], $d->modify('-7 days')->format('Y-m-d'), $yesterday)) ? round($a / 60, 1) : null,
            ];
        }

        // --- Belastung: akut (7 Tage) vs. chronisch (28 Tage)
        $acute = self::sumBetween($s['training_load'], $d->modify('-7 days')->format('Y-m-d'), $yesterday) / 7;
        $chronic = self::sumBetween($s['training_load'], $from28, $yesterday) / 28;
        $acwr = $chronic > 0 ? $acute / $chronic : ($acute > 0 ? 1.5 : 1.0);
        $loadScore = $acwr <= 1.0 ? 90 : ($acwr <= 1.3 ? 75 : ($acwr <= 1.5 ? 50 : 25));
        $yLoad = $s['training_load'][$yesterday] ?? 0;
        if ($yLoad > 70) {
            $loadScore -= 10;
        }
        $components['load'] = self::clamp($loadScore);

        // Trainingstage am Stück und Tage seit letztem Krafttraining
        $streak = 0;
        for ($i = 1; $i <= 14; $i++) {
            $dd = $d->modify("-$i days")->format('Y-m-d');
            if (($s['training_min'][$dd] ?? 0) >= 20) {
                $streak++;
            } else {
                break;
            }
        }
        $daysSinceStrength = null;
        for ($i = 1; $i <= 28; $i++) {
            $dd = $d->modify("-$i days")->format('Y-m-d');
            if (($s['strength_min'][$dd] ?? 0) >= 15) {
                $daysSinceStrength = $i;
                break;
            }
        }
        $facts['load'] = [
            'acute_per_day' => round($acute, 1),
            'chronic_per_day' => round($chronic, 1),
            'acwr' => round($acwr, 2),
            'yesterday_load' => round($yLoad),
            'training_days_in_row' => $streak,
            'days_since_strength' => $daysSinceStrength,
            'training_min_7d' => round(self::sumBetween($s['training_min'], $d->modify('-7 days')->format('Y-m-d'), $yesterday)),
        ];

        // --- Gesamt-Score (fehlende Teile werden herausgerechnet)
        $sum = 0;
        $wsum = 0;
        foreach ($components as $k => $v) {
            $sum += $v * self::WEIGHTS[$k];
            $wsum += self::WEIGHTS[$k];
        }
        $hasBodyData = isset($components['hrv']) || isset($components['sleep']) || isset($components['rhr']);
        $score = ($hasBodyData && $wsum > 0) ? (int)round($sum / $wsum) : null;

        return [
            'day' => $day,
            'score' => $score,
            'label' => self::label($score),
            'components' => array_map('intval', $components),
            'facts' => $facts,
        ];
    }

    public static function label(?int $score): string
    {
        if ($score === null) {
            return 'Keine Daten';
        }
        return $score >= 75 ? 'Top erholt' : ($score >= 60 ? 'Gut' : ($score >= 45 ? 'Mittel' : 'Erholung nötig'));
    }

    private static function clamp(float $v): float
    {
        return max(0, min(100, $v));
    }

    private static function avgBetween(array $series, string $from, string $to): ?float
    {
        $vals = [];
        foreach ($series as $day => $v) {
            if ($day >= $from && $day <= $to) {
                $vals[] = $v;
            }
        }
        return count($vals) >= 3 ? array_sum($vals) / count($vals) : null;
    }

    private static function sumBetween(array $series, string $from, string $to): float
    {
        $sum = 0.0;
        foreach ($series as $day => $v) {
            if ($day >= $from && $day <= $to) {
                $sum += $v;
            }
        }
        return $sum;
    }
}
