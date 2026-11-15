<?php
declare(strict_types=1);

// ---------- OTA calendar sync (admin page) ----------
function admin_ota(string $method): void
{
    $p = db();
    $rooms = $p->query('SELECT slug, name_json FROM rooms ORDER BY sort, id')->fetchAll();
    $slugs = array_column($rooms, 'slug');
    if ($method === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'add_feed') {
            $slug = (string)($_POST['room'] ?? '');
            $plat = (string)($_POST['platform'] ?? '');
            $url = trim((string)($_POST['url'] ?? ''));
            if (!in_array($slug, $slugs, true) || !isset(PLATFORMS[$plat]) || !preg_match('#^https://[^\s]{8,800}$#', $url)) {
                flash('Pick a room and platform, and paste an https:// calendar link.', 'err');
            } else {
                $p->prepare('INSERT INTO ical_feeds(room_slug, platform, url) VALUES(?,?,?)')->execute([$slug, $plat, $url]);
                $f = $p->query('SELECT * FROM ical_feeds ORDER BY id DESC LIMIT 1')->fetch();
                flash('Feed added. First sync: ' . ical_sync_feed($f));
            }
        } elseif ($do === 'del_feed') {
            $id = (int)($_POST['id'] ?? 0);
            $f = $p->prepare('SELECT * FROM ical_feeds WHERE id = ?');
            $f->execute([$id]);
            if ($f = $f->fetch()) {
                $p->prepare("UPDATE requests SET status = 'cancelled' WHERE source = ? AND ext_uid LIKE ? AND status = 'confirmed' AND checkout >= date('now')")->execute([$f['platform'], 'f' . $id . ':%']);
                $p->prepare('DELETE FROM ical_feeds WHERE id = ?')->execute([$id]);
                flash('Feed removed and its upcoming imported bookings were cancelled here.');
            }
        } elseif ($do === 'sync') {
            $res = ical_sync_all();
            flash($res ? 'Synced ' . count($res) . ' feed(s): ' . implode(' · ', array_unique($res)) : 'No feeds yet.');
        } elseif ($do === 'manual') {
            $slug = (string)($_POST['room'] ?? '');
            $plat = (string)($_POST['platform'] ?? '');
            $a = (string)($_POST['from'] ?? '');
            $b = (string)($_POST['to'] ?? '');
            if (!in_array($slug, $slugs, true) || !isset(PLATFORMS[$plat]) || !valid_range($a, $b) || $b <= $a) {
                flash('Pick room, platform and valid dates (check-out after check-in).', 'err');
            } else {
                $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80) ?: (PLATFORMS[$plat] . ' guest');
                $p->prepare("INSERT INTO requests(created_at, name, phone, room_slug, checkin, checkout, guests, lang, status, source, est_total, note) VALUES(?,?,?,?,?,?,?,'en','confirmed',?,?,?)")
                    ->execute([gmdate('Y-m-d H:i:s'), $name, mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 30), $slug, $a, $b, max(1, min(12, (int)($_POST['guests'] ?? 1))), $plat, max(0, (int)($_POST['amount'] ?? 0)), 'Added manually from ' . PLATFORMS[$plat]]);
                flash('Booking added. It now blocks those dates on the website.');
            }
        } elseif ($do === 'new_token') {
            set_setting('ical_token', bin2hex(random_bytes(16)));
            flash('New export links generated. Update them on every platform that imports them.');
        }
        redirect('/admin/ota');
    }

    $opts = '';
    foreach ($rooms as $r) {
        $opts .= '<option value="' . e($r['slug']) . '">' . e(tr_json($r['name_json'], 'en')) . '</option>';
    }
    $plats = '';
    foreach (PLATFORMS as $k => $l) {
        $plats .= '<option value="' . $k . '">' . e($l) . '</option>';
    }

    $h = '<section class="card"><h2>How it works</h2><ul class="todo">'
        . '<li><b>Platform → website:</b> paste the platform iCal (.ics) calendar link below. Its reservations block the same dates here, so the website never sells a room that is already taken. It refreshes every few minutes while people use the site, and through the cron job.</li>'
        . '<li><b>Website → platform:</b> give the platform the export link for each room type, so a booking confirmed here closes those dates there.</li>'
        . '<li>iCal carries <b>dates only</b> (no guest names). Not every platform offers an iCal link, and some only import or only export. For a platform without one, use a channel manager or add its bookings by hand in section 3.</li></ul></section>';

    $h .= '<section class="card"><h2>1) Import: platform calendars</h2><form method="post" class="row4 card-in">' . csrf_field() . '<input type="hidden" name="do" value="add_feed">'
        . '<label>Room type<select name="room">' . $opts . '</select></label><label>Platform<select name="platform">' . $plats . '</select></label>'
        . '<label style="grid-column:span 2">Calendar link (https://...ics)<input name="url" required placeholder="https://" dir="ltr"></label><button class="btn">Add feed</button></form>'
        . '<table><tr><th>Room</th><th>Platform</th><th>Last sync (UTC)</th><th>Status</th><th>Active</th><th></th></tr>';
    $n = 0;
    foreach ($p->query('SELECT * FROM ical_feeds ORDER BY id') as $f) {
        $n++;
        $ok = str_starts_with($f['last_status'], 'OK');
        $h .= '<tr><td>' . e($f['room_slug']) . '</td><td>' . e(PLATFORMS[$f['platform']] ?? $f['platform']) . '</td><td>' . e($f['last_sync'] ?: 'never')
            . '</td><td><span class="pill ' . ($ok ? 'confirmed' : 'cancelled') . '">' . e($f['last_status'] ?: '-') . '</span></td><td>' . (int)$f['last_count']
            . '</td><td><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="del_feed"><input type="hidden" name="id" value="' . (int)$f['id']
            . '"><button class="btn sm danger" onclick="return confirm(\'Remove this feed?\')">Remove</button></form></td></tr>';
    }
    $h .= ($n ? '' : '<tr><td colspan="6" class="muted">No feeds yet.</td></tr>') . '</table>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="sync"><button class="btn">Sync now</button></form></section>';

    $h .= '<section class="card"><h2>2) Export: links to give each platform</h2><p class="muted">Private links, keep them secret. Website bookings you confirm and closures appear in these feeds.</p><table><tr><th>Room type</th><th>Export link</th></tr>';
    foreach ($rooms as $r) {
        $h .= '<tr><td>' . e(tr_json($r['name_json'], 'en')) . '</td><td><input readonly value="' . e(ical_export_url($r['slug'])) . '" dir="ltr" onclick="this.select()"></td></tr>';
    }
    $h .= '</table><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="new_token"><button class="btn sm danger" onclick="return confirm(\'Old links stop working. Continue?\')">Generate new links</button></form></section>';

    $h .= '<section class="card"><h2>3) Add a platform booking by hand</h2><p class="muted">For platforms without iCal. It blocks those dates on the website immediately.</p>'
        . '<form method="post" class="row4">' . csrf_field() . '<input type="hidden" name="do" value="manual">'
        . '<label>Room type<select name="room">' . $opts . '</select></label><label>Platform<select name="platform">' . $plats . '</select></label>'
        . '<label>Guest name<input name="name"></label><label>Phone<input name="phone" dir="ltr"></label>'
        . '<label>Check-in<input type="date" name="from" required></label><label>Check-out<input type="date" name="to" required></label>'
        . '<label>Guests<input type="number" name="guests" min="1" max="12" value="1"></label><label>Amount (EGP, optional)<input type="number" name="amount" min="0"></label>'
        . '<button class="btn">Add booking</button></form></section>';
    admin_layout('OTA calendar sync', $h, 'ota');
}
