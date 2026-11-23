<?php
declare(strict_types=1);

/**
 * Liest Kalender über private ICS-Links (iCloud "Öffentlicher Kalender", Google "Privatadresse im iCal-Format").
 * Unterstützt Serientermine (täglich/wöchentlich/monatlich/jährlich) inkl. Ausnahmen.
 */
final class Ics
{
    private const WINDOW_PAST_DAYS = 7;
    private const WINDOW_FUTURE_DAYS = 35;

    /** Alle hinterlegten Kalender abrufen und speichern */
    public static function syncAll(): array
    {
        $urls = Settings::calendarUrls();
        if (!$urls) {
            return ['ok' => true, 'events' => 0, 'message' => 'Kein Kalender-Link hinterlegt'];
        }
        $from = now()->modify('-' . self::WINDOW_PAST_DAYS . ' days')->setTime(0, 0);
        $to = now()->modify('+' . self::WINDOW_FUTURE_DAYS . ' days')->setTime(23, 59, 59);
        $events = [];
        $errors = [];
        foreach ($urls as $url) {
            $ics = self::fetch($url);
            if ($ics === null) {
                $errors[] = parse_url($url, PHP_URL_HOST) ?: 'Kalender';
                continue;
            }
            $prefix = substr(md5($url), 0, 8) . '-';
            foreach (self::parse($ics, $from, $to) as $e) {
                $e['uid'] = mb_substr($prefix . $e['uid'], 0, 250);
                $events[] = $e;
            }
        }
        if ($errors) {
            Db::run('INSERT INTO d3_sync_log (kind, items, info) VALUES (?,?,?)', ['calendar', 0, 'Fehler: ' . implode(', ', $errors)]);
            return ['ok' => false, 'events' => 0, 'message' => 'Kalender nicht erreichbar: ' . implode(', ', $errors)];
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::run("DELETE FROM d3_calendar_events WHERE origin = 'ics'");
            foreach ($events as $e) {
                Db::run(
                    'INSERT IGNORE INTO d3_calendar_events (origin, uid, title, location, start_at, end_at, all_day) VALUES (?,?,?,?,?,?,?)',
                    ['ics', $e['uid'], mb_substr($e['title'], 0, 250), mb_substr($e['location'], 0, 250),
                     $e['start']->format('Y-m-d H:i:s'), $e['end']->format('Y-m-d H:i:s'), $e['all_day'] ? 1 : 0]
                );
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        Db::run('INSERT INTO d3_sync_log (kind, items, info) VALUES (?,?,?)', ['calendar', count($events), '']);
        return ['ok' => true, 'events' => count($events), 'message' => count($events) . ' Termine geladen'];
    }

    private static function fetch(string $url): ?string
    {
        $url = preg_replace('#^webcals?://#i', 'https://', trim($url));
        if (!preg_match('#^https://#i', $url)) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'dirks3n-calendar/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false || $status !== 200 || !str_contains((string)$body, 'BEGIN:VCALENDAR')) {
            app_log("ICS: Abruf fehlgeschlagen (HTTP $status) für " . parse_url($url, PHP_URL_HOST));
            return null;
        }
        return (string)$body;
    }

    /**
     * ICS-Text in Termine umwandeln (nur Termine im Zeitfenster).
     * @return array<int, array{uid:string,title:string,location:string,start:DateTimeImmutable,end:DateTimeImmutable,all_day:bool}>
     */
    public static function parse(string $ics, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $ics = str_replace(["\r\n", "\r"], "\n", $ics);
        $ics = preg_replace("/\n[ \t]/", '', $ics); // gefaltete Zeilen zusammenfügen
        $vevents = [];
        $cur = null;
        foreach (explode("\n", $ics) as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $cur = [];
                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($cur !== null) {
                    $vevents[] = $cur;
                }
                $cur = null;
                continue;
            }
            if ($cur === null || !str_contains($line, ':')) {
                continue;
            }
            [$left, $value] = explode(':', $line, 2);
            $parts = explode(';', $left);
            $name = strtoupper(array_shift($parts));
            $params = [];
            foreach ($parts as $p) {
                if (str_contains($p, '=')) {
                    [$pk, $pv] = explode('=', $p, 2);
                    $params[strtoupper($pk)] = trim($pv, '"');
                }
            }
            $cur[$name][] = ['v' => $value, 'p' => $params];
        }

        $overrides = [];
        $masters = [];
        foreach ($vevents as $ev) {
            if (strtoupper($ev['STATUS'][0]['v'] ?? '') === 'CANCELLED') {
                if (isset($ev['RECURRENCE-ID'])) {
                    $rid = self::parseDate($ev['RECURRENCE-ID'][0]);
                    if ($rid) {
                        $overrides[($ev['UID'][0]['v'] ?? '') . '|' . $rid[0]->format('Y-m-d H:i')] = null;
                    }
                }
                continue;
            }
            if (isset($ev['RECURRENCE-ID'])) {
                $rid = self::parseDate($ev['RECURRENCE-ID'][0]);
                if ($rid) {
                    $overrides[($ev['UID'][0]['v'] ?? '') . '|' . $rid[0]->format('Y-m-d H:i')] = $ev;
                }
                continue;
            }
            $masters[] = $ev;
        }

        $out = [];
        foreach ($masters as $ev) {
            $base = self::baseEvent($ev);
            if (!$base) {
                continue;
            }
            $duration = $base['end']->getTimestamp() - $base['start']->getTimestamp();
            $starts = isset($ev['RRULE']) ? self::expand($base['start'], $ev['RRULE'][0]['v'], $from, $to, self::exdates($ev)) : [$base['start']];
            foreach ($starts as $st) {
                $key = $base['uid'] . '|' . $st->format('Y-m-d H:i');
                if (array_key_exists($key, $overrides)) {
                    continue; // wird durch geänderte/abgesagte Instanz ersetzt
                }
                $en = $st->modify("+$duration seconds");
                if ($en < $from || $st > $to) {
                    continue;
                }
                $out[] = array_merge($base, ['start' => $st, 'end' => $en, 'uid' => $base['uid'] . '@' . $st->format('Ymd\THi')]);
            }
        }
        foreach ($overrides as $ev) {
            if ($ev === null) {
                continue;
            }
            $e = self::baseEvent($ev);
            if ($e && $e['end'] >= $from && $e['start'] <= $to) {
                $e['uid'] .= '@' . $e['start']->format('Ymd\THi') . '-x';
                $out[] = $e;
            }
        }
        usort($out, fn($a, $b) => $a['start'] <=> $b['start']);
        return $out;
    }

    private static function baseEvent(array $ev): ?array
    {
        if (!isset($ev['DTSTART'])) {
            return null;
        }
        $start = self::parseDate($ev['DTSTART'][0]);
        if (!$start) {
            return null;
        }
        [$st, $allDay] = $start;
        $end = isset($ev['DTEND']) ? self::parseDate($ev['DTEND'][0]) : null;
        $en = $end ? $end[0] : null;
        if (!$en && isset($ev['DURATION'])) {
            try {
                $en = $st->add(new DateInterval(ltrim($ev['DURATION'][0]['v'], '+')));
            } catch (Exception $e) {
                $en = null;
            }
        }
        if (!$en || $en < $st) {
            $en = $allDay ? $st->modify('+1 day') : $st->modify('+1 hour');
        }
        return [
            'uid' => (string)($ev['UID'][0]['v'] ?? md5(serialize($ev['DTSTART']))),
            'title' => self::unescape((string)($ev['SUMMARY'][0]['v'] ?? '(ohne Titel)')),
            'location' => self::unescape((string)($ev['LOCATION'][0]['v'] ?? '')),
            'start' => $st,
            'end' => $en,
            'all_day' => $allDay,
        ];
    }

    /** @return array{0:DateTimeImmutable,1:bool}|null [Datum, ganztägig] */
    private static function parseDate(array $prop): ?array
    {
        $v = trim($prop['v']);
        $local = new DateTimeZone(date_default_timezone_get());
        if (($prop['p']['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $v)) {
            $d = DateTimeImmutable::createFromFormat('!Ymd', substr($v, 0, 8), $local);
            return $d ? [$d, true] : null;
        }
        if (!preg_match('/^(\d{8}T\d{4,6})(Z?)$/', $v, $m)) {
            return null;
        }
        $fmt = strlen($m[1]) === 15 ? 'Ymd\THis' : 'Ymd\THi';
        if ($m[2] === 'Z') {
            $tz = new DateTimeZone('UTC');
        } else {
            $tz = $local;
            if (!empty($prop['p']['TZID'])) {
                try {
                    $tz = new DateTimeZone(self::mapTz($prop['p']['TZID']));
                } catch (Exception $e) {
                    $tz = $local;
                }
            }
        }
        $d = DateTimeImmutable::createFromFormat($fmt, $m[1], $tz);
        return $d ? [$d->setTimezone($local), false] : null;
    }

    private static function mapTz(string $tzid): string
    {
        $map = [
            'W. Europe Standard Time' => 'Europe/Berlin', 'Central Europe Standard Time' => 'Europe/Berlin',
            'Romance Standard Time' => 'Europe/Paris', 'GMT Standard Time' => 'Europe/London', 'UTC' => 'UTC',
        ];
        return $map[$tzid] ?? $tzid;
    }

    private static function exdates(array $ev): array
    {
        $out = [];
        foreach ($ev['EXDATE'] ?? [] as $prop) {
            foreach (explode(',', $prop['v']) as $v) {
                $d = self::parseDate(['v' => $v, 'p' => $prop['p']]);
                if ($d) {
                    // Ganztägige Ausnahme → ganzer Tag, sonst exakter Zeitpunkt
                    $out[$d[1] ? 'D:' . $d[0]->format('Y-m-d') : $d[0]->format('Y-m-d H:i')] = true;
                }
            }
        }
        return $out;
    }

    /** Serientermin aufklappen → Liste der Startzeitpunkte */
    private static function expand(DateTimeImmutable $start, string $rrule, DateTimeImmutable $from, DateTimeImmutable $to, array $ex): array
    {
        $r = [];
        foreach (explode(';', $rrule) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $r[strtoupper($k)] = $v;
            }
        }
        $freq = $r['FREQ'] ?? '';
        $interval = max(1, (int)($r['INTERVAL'] ?? 1));
        $count = isset($r['COUNT']) ? (int)$r['COUNT'] : null;
        $until = null;
        if (isset($r['UNTIL'])) {
            $u = self::parseDate(['v' => $r['UNTIL'], 'p' => []]);
            $until = $u ? ($u[1] ? $u[0]->setTime(23, 59, 59) : $u[0]) : null;
        }
        $byDay = isset($r['BYDAY']) ? explode(',', $r['BYDAY']) : [];
        $dayCodes = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
        $time = [(int)$start->format('G'), (int)$start->format('i'), (int)$start->format('s')];

        $candidates = [];
        $limit = 2000;
        // Alte Serien ohne COUNT: direkt bis kurz vor das Zeitfenster springen (spart Rechenzeit)
        if ($count === null && ($freq === 'DAILY' || $freq === 'WEEKLY') && $start < $from) {
            $step = $freq === 'DAILY' ? $interval : 7 * $interval;
            $periods = intdiv((int)(($from->getTimestamp() - $start->getTimestamp()) / 86400), $step) - 1;
            if ($periods > 0) {
                $start = $start->modify('+' . ($periods * $step) . ' days');
            }
        }
        if ($freq === 'DAILY') {
            for ($i = 0, $d = $start; $i < $limit; $i++, $d = $d->modify("+$interval days")) {
                $candidates[] = $d;
                if ($d > $to || ($until && $d > $until)) break;
            }
        } elseif ($freq === 'WEEKLY') {
            $days = $byDay ? array_values(array_filter(array_map(fn($c) => $dayCodes[substr($c, -2)] ?? null, $byDay))) : [(int)$start->format('N')];
            sort($days);
            $weekStart = $start->modify('-' . ((int)$start->format('N') - 1) . ' days')->setTime(0, 0);
            for ($i = 0, $w = $weekStart; $i < $limit / 7; $i++, $w = $w->modify('+' . (7 * $interval) . ' days')) {
                foreach ($days as $dn) {
                    $candidates[] = $w->modify('+' . ($dn - 1) . ' days')->setTime(...$time);
                }
                if ($w > $to || ($until && $w > $until)) break;
            }
        } elseif ($freq === 'MONTHLY') {
            $first = $start->modify('first day of this month')->setTime(...$time);
            for ($i = 0, $m = $first; $i < 600; $i++, $m = $m->modify("+$interval months")) {
                if ($byDay && preg_match('/^([+-]?\d)?(MO|TU|WE|TH|FR|SA|SU)$/', $byDay[0], $bm)) {
                    $n = $bm[1] === '' ? 1 : (int)$bm[1];
                    $names = ['MO' => 'monday', 'TU' => 'tuesday', 'WE' => 'wednesday', 'TH' => 'thursday', 'FR' => 'friday', 'SA' => 'saturday', 'SU' => 'sunday'];
                    $ord = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth', -1 => 'last'][$n] ?? 'first';
                    $c = $m->modify("$ord {$names[$bm[2]]} of this month")->setTime(...$time);
                    if ($c->format('m') === $m->format('m')) $candidates[] = $c;
                } else {
                    $dom = (int)$start->format('j');
                    if ($dom <= (int)$m->format('t')) $candidates[] = $m->setDate((int)$m->format('Y'), (int)$m->format('n'), $dom);
                }
                if ($m > $to || ($until && $m > $until)) break;
            }
        } elseif ($freq === 'YEARLY') {
            for ($i = 0, $y = $start; $i < 200; $i++, $y = $y->modify("+$interval years")) {
                $candidates[] = $y;
                if ($y > $to || ($until && $y > $until)) break;
            }
        } else {
            return [$start];
        }

        $out = [];
        $n = 0;
        foreach ($candidates as $c) {
            if ($c < $start) continue;
            if ($until && $c > $until) break;
            $n++;
            if ($count !== null && $n > $count) break;
            if (isset($ex[$c->format('Y-m-d H:i')]) || isset($ex['D:' . $c->format('Y-m-d')])) continue;
            if ($c > $to) break;
            if ($c >= $from->modify('-1 day')) $out[] = $c;
        }
        return $out;
    }

    private static function unescape(string $s): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s);
    }

    /** Termine eines Tages aus der Datenbank */
    public static function eventsForDay(string $day): array
    {
        $from = $day . ' 00:00:00';
        $to = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        return Db::all('SELECT title, location, start_at, end_at, all_day, origin FROM d3_calendar_events WHERE start_at < ? AND end_at > ? ORDER BY all_day DESC, start_at', [$to, $from]);
    }

    /** Freie Zeitfenster eines Tages (zwischen 06:00 und 22:00, mind. 30 Minuten) */
    public static function freeWindows(string $day, array $events, int $minMinutes = 30): array
    {
        $dayStart = strtotime("$day 06:00:00");
        $dayEnd = strtotime("$day 22:00:00");
        $nowTs = time();
        if (date('Y-m-d', $nowTs) === $day) {
            $dayStart = max($dayStart, $nowTs);
        }
        $busy = [];
        foreach ($events as $e) {
            if ((int)$e['all_day'] === 1) continue;
            $busy[] = [max($dayStart, strtotime($e['start_at'])), min($dayEnd, strtotime($e['end_at']))];
        }
        usort($busy, fn($a, $b) => $a[0] <=> $b[0]);
        $free = [];
        $cursor = $dayStart;
        foreach ($busy as [$s, $e]) {
            if ($e <= $cursor) continue;
            if ($s - $cursor >= $minMinutes * 60) $free[] = [$cursor, $s];
            $cursor = max($cursor, $e);
        }
        if ($dayEnd - $cursor >= $minMinutes * 60) $free[] = [$cursor, $dayEnd];
        return array_map(fn($w) => [
            'from' => date('H:i', $w[0]), 'to' => date('H:i', $w[1]), 'minutes' => (int)(($w[1] - $w[0]) / 60),
        ], $free);
    }
}
