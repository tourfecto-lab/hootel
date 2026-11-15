<?php
declare(strict_types=1);

/**
 * iCalendar (.ics) two-way availability sync with booking platforms.
 *  - IMPORT: pull each platform's iCal link per room type; reservations become confirmed bookings that block availability.
 *  - EXPORT: a private .ics link per room type (website bookings + closures) that platforms can import,
 *    so a website booking closes the same dates on the platform.
 * iCal carries dates only (no guest details). Platforms without iCal need a channel manager or the manual form.
 */

const PLATFORMS = [
    'booking' => 'Booking.com', 'agoda' => 'Agoda', 'expedia' => 'Expedia', 'hostelworld' => 'Hostelworld',
    'airbnb' => 'Airbnb', 'tripadvisor' => 'Tripadvisor', 'other' => 'Other platform',
];

function ical_token(): string
{
    $t = setting('ical_token');
    if (strlen($t) < 32) {
        $t = bin2hex(random_bytes(16));
        set_setting('ical_token', $t);
    }
    return $t;
}

function ical_export_url(string $slug): string
{
    return base_url() . '/ical/' . ical_token() . '/' . $slug . '.ics';
}

// ---------- parsing ----------
/** @return array<int,array{uid:string,start:string,end:string,summary:string,cancelled:bool}> */
function parse_ical(string $text): array
{
    $text = preg_replace("/\r?\n[ \t]/", '', $text); // unfold continuation lines
    $events = [];
    $cur = null;
    foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
        $line = rtrim($line);
        if ($line === 'BEGIN:VEVENT') {
            $cur = ['uid' => '', 'start' => '', 'end' => '', 'summary' => '', 'cancelled' => false];
            continue;
        }
        if ($line === 'END:VEVENT') {
            if ($cur && $cur['start'] !== '') {
                if ($cur['end'] === '' || $cur['end'] <= $cur['start']) {
                    $cur['end'] = (new DateTime($cur['start']))->modify('+1 day')->format('Y-m-d');
                }
                if ($cur['uid'] === '') {
                    $cur['uid'] = md5($cur['start'] . $cur['end'] . $cur['summary']);
                }
                $events[] = $cur;
            }
            $cur = null;
            continue;
        }
        if ($cur === null || !str_contains($line, ':')) {
            continue;
        }
        [$left, $val] = explode(':', $line, 2);
        $prop = strtoupper(explode(';', $left)[0]);
        switch ($prop) {
            case 'UID': $cur['uid'] = mb_substr(trim($val), 0, 200); break;
            case 'SUMMARY': $cur['summary'] = mb_substr(trim(str_replace(['\\,', '\\;', '\\n', '\\N'], [',', ';', ' ', ' '], $val)), 0, 120); break;
            case 'STATUS': $cur['cancelled'] = strtoupper(trim($val)) === 'CANCELLED'; break;
            case 'DTSTART': $cur['start'] = ical_date($val); break;
            case 'DTEND': $cur['end'] = ical_date($val); break;
        }
    }
    return array_values(array_filter($events, fn($e) => $e['start'] !== ''));
}

function ical_date(string $v): string
{
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', trim($v), $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return "$m[1]-$m[2]-$m[3]";
    }
    return '';
}

// ---------- safe fetch (SSRF-resistant) ----------
function ical_fetch(string $url): string
{
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || (isset($p['port']) && (int)$p['port'] !== 443)) {
        throw new RuntimeException('Only https:// links on the standard port are allowed');
    }
    $host = $p['host'];
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) {
        throw new RuntimeException('Could not resolve host');
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Address not allowed');
        }
    }
    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RESOLVE => [$host . ':443:' . $ips[0]], // pin the validated IP
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'KingsPalace-CalendarSync/1.0',
        CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body) {
            $body .= $chunk;
            return strlen($body) > 1_500_000 ? 0 : strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($ok === false || $code !== 200) {
        throw new RuntimeException($err !== '' ? $err : "HTTP $code");
    }
    if (!str_contains($body, 'BEGIN:VCALENDAR')) {
        throw new RuntimeException('Not an iCalendar feed');
    }
    return $body;
}

// ---------- apply ----------
/** Insert/update/cancel imported bookings for one feed. Returns [active events, newly cancelled]. */
function ical_apply(array $feed, array $events): array
{
    $p = db();
    $prefix = 'f' . (int)$feed['id'] . ':';
    $today = date('Y-m-d');
    $label = PLATFORMS[$feed['platform']] ?? 'OTA';
    $seen = [];
    $active = 0;
    $up = $p->prepare("INSERT INTO requests(created_at, name, phone, room_slug, checkin, checkout, guests, lang, status, source, ext_uid, note)
        VALUES(?,?,?,?,?,?,1,'en',?,?,?,?)
        ON CONFLICT(source, ext_uid) WHERE ext_uid <> '' DO UPDATE SET checkin = excluded.checkin, checkout = excluded.checkout, status = excluded.status, room_slug = excluded.room_slug");
    foreach ($events as $e) {
        if ($e['end'] < $today) {
            continue; // past stays are irrelevant
        }
        $uid = $prefix . $e['uid'];
        $seen[$uid] = true;
        $status = $e['cancelled'] ? 'cancelled' : 'confirmed';
        $active += $e['cancelled'] ? 0 : 1;
        $name = $e['summary'] !== '' ? $e['summary'] : $label . ' booking';
        $up->execute([gmdate('Y-m-d H:i:s'), $name, '', $feed['room_slug'], $e['start'], $e['end'], $status, $feed['platform'], $uid, 'Imported from ' . $label . ' calendar']);
    }
    // bookings that disappeared from the feed were cancelled on the platform
    $cancelled = 0;
    $st = $p->prepare("SELECT id, ext_uid FROM requests WHERE source = ? AND ext_uid LIKE ? AND status = 'confirmed' AND checkout >= ?");
    $st->execute([$feed['platform'], $prefix . '%', $today]);
    foreach ($st->fetchAll() as $r) {
        if (!isset($seen[$r['ext_uid']])) {
            $p->prepare("UPDATE requests SET status = 'cancelled' WHERE id = ?")->execute([$r['id']]);
            $cancelled++;
        }
    }
    return [$active, $cancelled];
}

function ical_sync_feed(array $feed): string
{
    $p = db();
    try {
        $events = parse_ical(ical_fetch($feed['url']));
        [$active, $cancelled] = ical_apply($feed, $events);
        $msg = 'OK' . ($cancelled ? " ($cancelled cancelled on platform)" : '');
        $p->prepare('UPDATE ical_feeds SET last_sync = ?, last_status = ?, last_count = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $msg, $active, $feed['id']]);
        return $msg;
    } catch (Throwable $ex) {
        $msg = 'Error: ' . mb_substr($ex->getMessage(), 0, 120);
        $p->prepare('UPDATE ical_feeds SET last_sync = ?, last_status = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $msg, $feed['id']]);
        return $msg;
    }
}

function ical_sync_all(): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM ical_feeds ORDER BY id')->fetchAll() as $f) {
        $out[$f['id']] = ical_sync_feed($f);
    }
    return $out;
}

/** Cheap guard so availability is never based on very stale platform data: sync if the oldest feed is older than $maxAge seconds. */
function ical_sync_if_stale(int $maxAge = 600): void
{
    $oldest = db()->query("SELECT MIN(CASE WHEN last_sync = '' THEN '0000' ELSE last_sync END) FROM ical_feeds")->fetchColumn();
    if ($oldest === null || $oldest === false) {
        return; // no feeds
    }
    if ($oldest === '0000' || strtotime($oldest . ' UTC') < time() - $maxAge) {
        ical_sync_all();
    }
}

// ---------- export ----------
function ical_export(string $slug): void
{
    $p = db();
    $now = gmdate('Ymd\THis\Z');
    $out = ["BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//Kings Palace//Availability//EN", "CALSCALE:GREGORIAN", "METHOD:PUBLISH"];
    $ev = function (string $uid, string $from, string $toExclusive, string $summary) use (&$out, $now) {
        $out[] = 'BEGIN:VEVENT';
        $out[] = 'UID:' . $uid . '@kingspalace';
        $out[] = 'DTSTAMP:' . $now;
        $out[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $from);
        $out[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', $toExclusive);
        $out[] = 'SUMMARY:' . $summary;
        $out[] = 'END:VEVENT';
    };
    // only website bookings are exported (imported OTA bookings must not loop back to other platforms)
    $st = $p->prepare("SELECT id, checkin, checkout FROM requests WHERE room_slug = ? AND status = 'confirmed' AND source = 'direct' AND checkout >= date('now')");
    $st->execute([$slug]);
    foreach ($st as $r) {
        $ev('req' . $r['id'], $r['checkin'], $r['checkout'], 'Reserved');
    }
    $st = $p->prepare("SELECT id, from_date, to_date FROM blocks WHERE room_slug = ? AND to_date >= date('now')");
    $st->execute([$slug]);
    foreach ($st as $r) {
        $ev('block' . $r['id'], $r['from_date'], (new DateTime($r['to_date']))->modify('+1 day')->format('Y-m-d'), 'Not available');
    }
    $out[] = 'END:VCALENDAR';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Cache-Control: no-store');
    echo implode("\r\n", $out) . "\r\n";
}
