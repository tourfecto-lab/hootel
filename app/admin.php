<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_extra.php';
require_once __DIR__ . '/admin_ota.php';
require_once __DIR__ . '/admin_report.php';

const ADMIN_IDLE = 7200;
const STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled'];

function admin_dispatch(string $path, string $method): void
{
    security_headers(true);
    $route = trim(substr($path, strlen('/admin')), '/');

    if ($route === 'login') {
        admin_login($method);
        return;
    }
    // auth gate
    if (empty($_SESSION['admin_id']) || (time() - (int)($_SESSION['admin_seen'] ?? 0)) > ADMIN_IDLE) {
        unset($_SESSION['admin_id']);
        redirect('/admin/login');
    }
    $_SESSION['admin_seen'] = time();

    if ($method === 'POST' && !csrf_ok()) {
        http_response_code(400);
        echo 'Invalid request (CSRF). Go back and retry.';
        return;
    }

    // staff accounts only handle day-to-day work: requests, calendar, own password
    $staffOk = ['', 'dashboard', 'requests', 'requests.csv', 'calendar', 'account', 'logout'];
    if (($_SESSION['admin_role'] ?? 'admin') !== 'admin' && !in_array($route, $staffOk, true)) {
        http_response_code(403);
        admin_layout('Not allowed', '<p>Your account does not have access to this page.</p>', '');
        return;
    }
    if ($method === 'POST' && $route !== 'logout') {
        audit('POST /' . $route, (string)($_POST['do'] ?? ''));
    }

    switch ($route) {
        case '': case 'dashboard': admin_dashboard(); break;
        case 'requests': admin_requests($method); break;
        case 'requests.csv': admin_requests_csv(); break;
        case 'calendar': admin_calendar(); break;
        case 'pricing': admin_pricing($method); break;
        case 'ota': admin_ota($method); break;
        case 'report': admin_report($method); break;
        case 'report.csv': admin_report_csv(); break;
        case 'faq': admin_items($method, 'faq'); break;
        case 'policies': admin_items($method, 'policy'); break;
        case 'nearby': admin_items($method, 'attraction'); break;
        case 'testimonials': admin_items($method, 'testimonial'); break;
        case 'users': admin_users($method); break;
        case 'audit': admin_audit(); break;
        case 'backup': admin_backup($method); break;
        case 'rooms': admin_rooms($method); break;
        case 'amenities': admin_amenities($method); break;
        case 'content': admin_content($method); break;
        case 'links': admin_links($method); break;
        case 'gallery': admin_gallery($method); break;
        case 'account': admin_account($method); break;
        case 'logout':
            if ($method === 'POST') {
                $_SESSION = [];
                session_destroy();
                redirect('/admin/login');
            }
            redirect('/admin');
            break;
        default: http_response_code(404); admin_layout('Not found', '<p>Page not found.</p>', '');
    }
}

// ---------- layout ----------
function admin_layout(string $title, string $body, string $active): void
{
    $isAdmin = ($_SESSION['admin_role'] ?? 'admin') === 'admin';
    $nav = ['dashboard' => ['/admin', 'Dashboard'], 'requests' => ['/admin/requests', 'Booking requests'], 'calendar' => ['/admin/calendar', 'Availability calendar']];
    if ($isAdmin) {
        $nav += ['report' => ['/admin/report', 'Monthly report'], 'ota' => ['/admin/ota', 'OTA calendar sync'], 'rooms' => ['/admin/rooms', 'Rooms & prices'], 'pricing' => ['/admin/pricing', 'Seasons & closures'],
            'amenities' => ['/admin/amenities', 'Amenities'], 'gallery' => ['/admin/gallery', 'Gallery'],
            'faq' => ['/admin/faq', 'FAQ'], 'policies' => ['/admin/policies', 'Policies'], 'nearby' => ['/admin/nearby', 'Nearby places'],
            'testimonials' => ['/admin/testimonials', 'Guest reviews'], 'links' => ['/admin/links', 'Platforms & social'],
            'content' => ['/admin/content', 'Content & settings'], 'users' => ['/admin/users', 'Users'], 'audit' => ['/admin/audit', 'Activity log'], 'backup' => ['/admin/backup', 'Backup']];
    }
    $nav['account'] = ['/admin/account', 'Account'];
    $new = (int)db()->query("SELECT COUNT(*) FROM requests WHERE status = 'new'")->fetchColumn();
    $f = flash();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . e($title) . ' · Admin</title><link rel="stylesheet" href="/assets/admin.css"></head><body>'
        . '<aside><div class="logo">👑 ' . e(tr_json(setting('name_json'), 'en')) . '<small>Admin</small></div><nav>';
    foreach ($nav as $k => [$href, $label]) {
        echo '<a href="' . $href . '"' . ($k === $active ? ' class="on"' : '') . '>' . e($label)
            . ($k === 'requests' && $new ? ' <b class="badge">' . $new . '</b>' : '') . '</a>';
    }
    echo '<a href="/" target="_blank" rel="noopener">View site ↗</a>'
        . '<form method="post" action="/admin/logout">' . csrf_field() . '<button class="link">Sign out</button></form></nav></aside><main>'
        . '<h1>' . e($title) . '</h1>';
    if ($f) {
        echo '<div class="flash ' . e($f['t']) . '">' . e($f['m']) . '</div>';
    }
    echo $body . '</main><script src="/assets/admin.js"></script></body></html>';
}

/** Per-language input group, rendered as tabs. $name = field prefix, $json = current JSON map. */
function lang_inputs(string $name, ?string $json, bool $textarea = false): string
{
    $m = json_decode($json ?: '{}', true) ?: [];
    $enabled = enabled_langs();
    $tabs = $panes = '';
    $i = 0;
    foreach (LANGS as $code => $meta) {
        $dim = in_array($code, $enabled, true) ? '' : ' off';
        $tabs .= '<button type="button" class="tab' . ($i === 0 ? ' on' : '') . $dim . '" data-t="' . $code . '">' . e($code) . '</button>';
        $val = (string)($m[$code] ?? '');
        $field = $textarea
            ? '<textarea name="' . e($name) . '[' . $code . ']" rows="3" dir="' . ($meta['rtl'] ? 'rtl' : 'ltr') . '">' . e($val) . '</textarea>'
            : '<input name="' . e($name) . '[' . $code . ']" value="' . e($val) . '" dir="' . ($meta['rtl'] ? 'rtl' : 'ltr') . '">';
        $panes .= '<div class="pane' . ($i === 0 ? ' on' : '') . '" data-p="' . $code . '"><span class="lg">' . e($meta['name']) . '</span>' . $field . '</div>';
        $i++;
    }
    return '<div class="ltabs"><div class="tabs">' . $tabs . '</div>' . $panes . '</div>';
}

function collect_lang(string $name): string
{
    $in = $_POST[$name] ?? [];
    $out = [];
    foreach (LANGS as $code => $_) {
        $v = is_array($in) ? trim((string)($in[$code] ?? '')) : '';
        if ($v !== '') {
            $out[$code] = mb_substr($v, 0, 2000);
        }
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

// ---------- login / account ----------
function admin_login(string $method): void
{
    $err = '';
    if ($method === 'POST') {
        if (!csrf_ok()) {
            $err = 'Session expired, try again.';
        } elseif (!throttle('login', 8, 900)) {
            $err = 'Too many attempts. Wait 15 minutes.';
        } else {
            $st = db()->prepare('SELECT * FROM admins WHERE username = ?');
            $st->execute([trim((string)($_POST['username'] ?? ''))]);
            $a = $st->fetch();
            if ($a && password_verify((string)($_POST['password'] ?? ''), $a['pass_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$a['id'];
                $_SESSION['admin_user'] = $a['username'];
                $_SESSION['admin_role'] = $a['role'] ?? 'admin';
                $_SESSION['admin_seen'] = time();
                audit('login');
                redirect('/admin');
            }
            $err = 'Wrong username or password.';
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Sign in</title><link rel="stylesheet" href="/assets/admin.css"></head>'
        . '<body class="login"><form method="post" class="box">' . csrf_field() . '<h1>👑 Admin</h1>'
        . ($err ? '<div class="flash err">' . e($err) . '</div>' : '')
        . '<label>Username<input name="username" autocomplete="username" required autofocus></label>'
        . '<label>Password<input type="password" name="password" autocomplete="current-password" required></label>'
        . '<button class="btn">Sign in</button></form></body></html>';
}

function admin_account(string $method): void
{
    if ($method === 'POST') {
        $a = db()->prepare('SELECT * FROM admins WHERE id = ?');
        $a->execute([$_SESSION['admin_id']]);
        $a = $a->fetch();
        $new = (string)($_POST['new'] ?? '');
        if (!$a || !password_verify((string)($_POST['current'] ?? ''), $a['pass_hash'])) {
            flash('Current password is wrong.', 'err');
        } elseif (strlen($new) < 10) {
            flash('New password must be at least 10 characters.', 'err');
        } elseif ($new !== ($_POST['confirm'] ?? '')) {
            flash('Passwords do not match.', 'err');
        } else {
            db()->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $a['id']]);
            flash('Password changed.');
        }
        redirect('/admin/account');
    }
    admin_layout('Account', '<form method="post" class="card narrow">' . csrf_field()
        . '<label>Current password<input type="password" name="current" required autocomplete="current-password"></label>'
        . '<label>New password (10+ characters)<input type="password" name="new" required minlength="10" autocomplete="new-password"></label>'
        . '<label>Confirm new password<input type="password" name="confirm" required autocomplete="new-password"></label>'
        . '<button class="btn">Change password</button></form>', 'account');
}

// ---------- dashboard ----------
function admin_dashboard(): void
{
    $p = db();
    $q = fn(string $sql) => (int)$p->query($sql)->fetchColumn();
    $stats = [
        ['New requests', $q("SELECT COUNT(*) FROM requests WHERE status='new'"), '/admin/requests?status=new'],
        ['Requests (30 days)', $q("SELECT COUNT(*) FROM requests WHERE created_at >= datetime('now','-30 day')"), '/admin/requests'],
        ['Confirmed', $q("SELECT COUNT(*) FROM requests WHERE status='confirmed'"), '/admin/requests?status=confirmed'],
        ['Visits (30 days)', $q("SELECT COALESCE(SUM(n),0) FROM visits WHERE day >= date('now','-30 day')"), ''],
        ['Confirmed upcoming (EGP, est.)', $q("SELECT COALESCE(SUM(est_total),0) FROM requests WHERE status='confirmed' AND checkout >= date('now')"), '/admin/calendar'],
    ];
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $days[gmdate('Y-m-d', strtotime("-$i day"))] = 0;
    }
    foreach ($p->query("SELECT day, SUM(n) n FROM visits WHERE day >= date('now','-13 day') GROUP BY day") as $r) {
        if (isset($days[$r['day']])) {
            $days[$r['day']] = (int)$r['n'];
        }
    }
    $mx = max(1, max($days));
    $bars = '';
    foreach ($days as $d => $n) {
        $bars .= '<div class="bar" title="' . e("$d: $n") . '"><i style="height:' . round($n / $mx * 100) . '%"></i><span>' . e(substr($d, 8)) . '</span></div>';
    }
    $byLang = '';
    foreach ($p->query("SELECT lang, SUM(n) n FROM visits WHERE day >= date('now','-30 day') GROUP BY lang ORDER BY n DESC") as $r) {
        $byLang .= '<li><span>' . e(LANGS[$r['lang']]['name'] ?? $r['lang']) . '</span><b>' . (int)$r['n'] . '</b></li>';
    }
    $byLang = $byLang ?: '<li class="muted">No visits yet.</li>';

    $rows = '';
    foreach ($p->query('SELECT * FROM requests ORDER BY id DESC LIMIT 6') as $r) {
        $rows .= '<tr><td>' . e($r['name']) . '</td><td>' . e($r['checkin']) . ' → ' . e($r['checkout']) . '</td><td>' . e($r['room_slug'])
            . '</td><td><span class="pill ' . e($r['status']) . '">' . e(STATUSES[$r['status']] ?? $r['status']) . '</span></td></tr>';
    }
    $rows = $rows ?: '<tr><td colspan="4" class="muted">No requests yet.</td></tr>';

    $todo = [];
    foreach (['whatsapp' => 'WhatsApp number', 'checkin' => 'Check-in time', 'checkout' => 'Check-out time', 'email' => 'Contact email', 'site_url' => 'Site URL (for SEO)'] as $k => $label) {
        if (setting($k) === '') {
            $todo[] = $label;
        }
    }
    if (!$q('SELECT COUNT(*) FROM gallery')) {
        $todo[] = 'Gallery photos';
    }
    if (!$q('SELECT COUNT(*) FROM amenities WHERE confirmed = 1')) {
        $todo[] = 'Confirm amenities';
    }
    $links = $q("SELECT COUNT(*) FROM links WHERE url = ''");
    if ($links) {
        $todo[] = "$links platform links";
    }

    $h = '<div class="stats">';
    foreach ($stats as [$l, $v, $href]) {
        $h .= ($href ? '<a href="' . $href . '"' : '<div') . ' class="stat"><b>' . $v . '</b><span>' . e($l) . '</span>' . ($href ? '</a>' : '</div>');
    }
    $h .= '</div><div class="cols"><section class="card"><h2>Visits, last 14 days</h2><div class="bars">' . $bars . '</div></section>'
        . '<section class="card"><h2>Visitors by language (30 days)</h2><ul class="kv">' . $byLang . '</ul></section></div>'
        . '<section class="card"><h2>Latest requests</h2><table><tr><th>Name</th><th>Dates</th><th>Room</th><th>Status</th></tr>' . $rows . '</table></section>';
    if ($todo) {
        $h .= '<section class="card"><h2>Still to fill in</h2><ul class="todo">';
        foreach ($todo as $t) {
            $h .= '<li>' . e($t) . '</li>';
        }
        $h .= '</ul></section>';
    }
    $h .= dashboard_marketing();
    admin_layout('Dashboard', $h, 'dashboard');
}

// ---------- requests ----------
function admin_requests(string $method): void
{
    $p = db();
    if ($method === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if (($_POST['do'] ?? '') === 'delete') {
            $p->prepare('DELETE FROM requests WHERE id = ?')->execute([$id]);
            flash('Request deleted.');
        } else {
            $st = (string)($_POST['status'] ?? 'new');
            if (isset(STATUSES[$st])) {
                $blocked = false;
                if ($st === 'confirmed') { // do not confirm into a full or closed room type
                    $q = $p->prepare('SELECT r.*, rm.qty, rm.unit, rm.slug FROM requests r JOIN rooms rm ON rm.slug = r.room_slug WHERE r.id = ?');
                    $q->execute([$id]);
                    if ($rq = $q->fetch()) {
                        $b = $p->prepare('SELECT COUNT(*) FROM blocks WHERE room_slug = ? AND from_date < ? AND to_date >= ?');
                        $b->execute([$rq['room_slug'], $rq['checkout'], $rq['checkin']]);
                        $c = $p->prepare("SELECT COUNT(*) FROM requests WHERE room_slug = ? AND status = 'confirmed' AND id <> ? AND checkin < ? AND checkout > ?");
                        $c->execute([$rq['room_slug'], $id, $rq['checkout'], $rq['checkin']]);
                        $blocked = (int)$b->fetchColumn() > 0 || ($rq['unit'] === 'room' && (int)$c->fetchColumn() >= (int)$rq['qty']);
                    }
                }
                if ($blocked) {
                    flash('Cannot confirm: that room type is full or closed for these dates. See the Availability calendar.', 'err');
                } else {
                    $attr = in_array(($_POST['attrib'] ?? ''), ['agency', 'organic'], true) ? (string)$_POST['attrib'] : '';
                    $p->prepare('UPDATE requests SET status = ?, note = ?, attrib = ? WHERE id = ?')->execute([$st, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000), $attr, $id]);
                    flash('Saved.');
                }
            }
        }
        redirect('/admin/requests' . (!empty($_POST['back']) && preg_match('/^[a-z]+$/', (string)$_POST['back']) ? '?status=' . $_POST['back'] : ''));
    }
    $f = (string)($_GET['status'] ?? '');
    $rows = $f !== '' && isset(STATUSES[$f])
        ? $p->prepare('SELECT * FROM requests WHERE status = ? ORDER BY id DESC LIMIT 300') : $p->prepare('SELECT * FROM requests ORDER BY id DESC LIMIT 300');
    $rows->execute($f !== '' && isset(STATUSES[$f]) ? [$f] : []);
    $h = '<div class="filters"><a href="/admin/requests"' . ($f === '' ? ' class="on"' : '') . '>All</a>';
    foreach (STATUSES as $k => $l) {
        $h .= '<a href="/admin/requests?status=' . $k . '"' . ($f === $k ? ' class="on"' : '') . '>' . e($l) . '</a>';
    }
    $h .= '<a class="right" href="/admin/requests.csv">⬇ Export CSV</a></div>';
    $any = false;
    foreach ($rows as $r) {
        $any = true;
        $wa = wa_digits($r["phone"]);
        $nights = max(1, (int)(new DateTime($r['checkin']))->diff(new DateTime($r['checkout']))->days);
        $h .= '<form method="post" class="card req">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="back" value="' . e($f) . '">'
            . '<div class="reqhead"><b>' . e($r['name']) . '</b> <span class="muted">#' . (int)$r['id'] . ' · ' . e($r['created_at']) . ' UTC · ' . e(strtoupper($r['lang'])) . '</span>'
            . ($r['source'] !== 'direct' ? '<span class="pill src">' . e(PLATFORMS[$r['source']] ?? $r['source']) . '</span>' : '')
            . '<span class="pill ' . e($r['status']) . '">' . e(STATUSES[$r['status']] ?? $r['status']) . '</span></div>'
            . '<div class="reqbody"><div>📅 ' . e($r['checkin']) . ' → ' . e($r['checkout']) . ' (' . $nights . ' nights) · 👥 ' . (int)$r['guests'] . ' · 🛏 ' . e($r['room_slug'])
            . ((int)$r['est_total'] > 0 ? ' · 💰 ' . number_format((int)$r['est_total']) . ' EGP (est.)' : '') . '</div>'
            . '<div>📞 <a href="tel:' . e($r['phone']) . '" dir="ltr">' . e($r['phone']) . '</a>'
            . ($wa ? ' · <a target="_blank" rel="noopener" href="https://wa.me/' . e($wa) . '">WhatsApp</a>' : '') . '</div>'
            . ($r['message'] !== '' ? '<div class="msg">' . nl2br(e($r['message'])) . '</div>' : '') . '</div>'
            . '<div class="reqact"><select name="status">';
        foreach (STATUSES as $k => $l) {
            $h .= '<option value="' . $k . '"' . ($r['status'] === $k ? ' selected' : '') . '>' . e($l) . '</option>';
        }
        $h .= '</select>' . attrib_select($r) . '<input name="note" placeholder="Internal note" value="' . e($r['note']) . '"><button class="btn sm">Save</button>'
            . '<button class="btn sm danger" name="do" value="delete" onclick="return confirm(\'Delete this request?\')">Delete</button>' . wa_buttons($r) . '</div></form>';
    }
    if (!$any) {
        $h .= '<p class="muted">No requests here yet.</p>';
    }
    admin_layout('Booking requests', $h, 'requests');
}

function admin_requests_csv(): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="requests-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['id', 'created_utc', 'name', 'phone', 'room', 'checkin', 'checkout', 'guests', 'lang', 'status', 'message', 'note']);
    foreach (db()->query('SELECT * FROM requests ORDER BY id DESC') as $r) {
        // prefix risky leading characters so spreadsheets don't execute them as formulas
        $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v;
        fputcsv($o, [$r['id'], $r['created_at'], $safe($r['name']), $safe($r['phone']), $r['room_slug'], $r['checkin'], $r['checkout'], $r['guests'], $r['lang'], $r['status'], $safe($r['message']), $safe($r['note'])]);
    }
    fclose($o);
}

// ---------- rooms ----------
function admin_rooms(string $method): void
{
    $p = db();
    if ($method === 'POST') {
        $do = (string)($_POST['do'] ?? 'save');
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($do === 'add') {
                $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim((string)($_POST['slug'] ?? ''))));
                if ($slug === '') {
                    throw new RuntimeException('Give the room a short code, e.g. quad');
                }
                $p->prepare('INSERT INTO rooms(slug, price, unit, guests, qty, sort, name_json, desc_json) VALUES(?,?,?,?,?,?,?,?)')
                    ->execute([$slug, 0, 'room', 1, 1, 99, json_encode(['en' => ucfirst($slug)]), '{}']);
                flash('Room added. Fill in its details below.');
            } elseif ($do === 'delete') {
                $r = $p->prepare('SELECT photos_json FROM rooms WHERE id = ?');
                $r->execute([$id]);
                foreach (json_decode((string)$r->fetchColumn(), true) ?: [] as $ph) {
                    delete_upload($ph);
                }
                $p->prepare('DELETE FROM rooms WHERE id = ?')->execute([$id]);
                flash('Room deleted.');
            } else {
                $r = $p->prepare('SELECT * FROM rooms WHERE id = ?');
                $r->execute([$id]);
                $room = $r->fetch();
                if (!$room) {
                    throw new RuntimeException('Room not found');
                }
                $photos = json_decode($room['photos_json'], true) ?: [];
                foreach ((array)($_POST['rm_photo'] ?? []) as $ph) {
                    if (($k = array_search($ph, $photos, true)) !== false) {
                        delete_upload($ph);
                        unset($photos[$k]);
                    }
                }
                if (!empty($_FILES['photos']['name'][0])) {
                    foreach ($_FILES['photos']['name'] as $i => $_) {
                        if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                            continue;
                        }
                        $photos[] = save_upload(['tmp_name' => $_FILES['photos']['tmp_name'][$i], 'size' => $_FILES['photos']['size'][$i], 'error' => $_FILES['photos']['error'][$i]]);
                    }
                }
                $unit = ($_POST['unit'] ?? 'room') === 'bed' ? 'bed' : 'room';
                $p->prepare('UPDATE rooms SET price=?, unit=?, guests=?, qty=?, sort=?, active=?, name_json=?, desc_json=?, photos_json=? WHERE id=?')->execute([
                    max(0, (int)($_POST['price'] ?? 0)), $unit, max(1, (int)($_POST['guests'] ?? 1)), max(1, (int)($_POST['qty'] ?? 1)),
                    (int)($_POST['sort'] ?? 0), empty($_POST['active']) ? 0 : 1, collect_lang('name'), collect_lang('desc'),
                    json_encode(array_values($photos)), $id,
                ]);
                flash('Room saved.');
            }
        } catch (Throwable $ex) {
            flash($ex instanceof PDOException ? 'That code is already used.' : $ex->getMessage(), 'err');
        }
        redirect('/admin/rooms');
    }
    $h = '';
    foreach ($p->query('SELECT * FROM rooms ORDER BY sort, id') as $r) {
        $photos = json_decode($r['photos_json'], true) ?: [];
        $h .= '<form method="post" enctype="multipart/form-data" class="card">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
            . '<h2>' . e(tr_json($r['name_json'], 'en')) . ' <small class="muted">(' . e($r['slug']) . ')</small></h2>'
            . '<div class="row4"><label>Price (EGP)<input type="number" name="price" min="0" value="' . (int)$r['price'] . '"></label>'
            . '<label>Sold per<select name="unit"><option value="room"' . ($r['unit'] === 'room' ? ' selected' : '') . '>Room / night</option><option value="bed"' . ($r['unit'] === 'bed' ? ' selected' : '') . '>Bed / night</option></select></label>'
            . '<label>Guests<input type="number" name="guests" min="1" value="' . (int)$r['guests'] . '"></label>'
            . '<label>Quantity<input type="number" name="qty" min="1" value="' . (int)$r['qty'] . '"></label>'
            . '<label>Order<input type="number" name="sort" value="' . (int)$r['sort'] . '"></label>'
            . '<label class="chk"><input type="checkbox" name="active" value="1"' . ($r['active'] ? ' checked' : '') . '> Show on site</label></div>'
            . '<label>Name</label>' . lang_inputs('name', $r['name_json'])
            . '<label>Description</label>' . lang_inputs('desc', $r['desc_json'], true)
            . '<label>Photos</label><div class="thumbs">';
        foreach ($photos as $ph) {
            $h .= '<label class="th"><img src="' . e($ph) . '" alt=""><span><input type="checkbox" name="rm_photo[]" value="' . e($ph) . '"> remove</span></label>';
        }
        $h .= '</div><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>'
            . '<div class="actions"><button class="btn">Save room</button>'
            . '<button class="btn danger" name="do" value="delete" onclick="return confirm(\'Delete this room and its photos?\')">Delete</button></div></form>';
    }
    $h .= '<form method="post" class="card narrow">' . csrf_field() . '<h2>Add a room type</h2><label>Short code (letters, numbers, dashes)<input name="slug" required placeholder="quad"></label><input type="hidden" name="do" value="add"><button class="btn">Add</button></form>';
    admin_layout('Rooms & prices', $h, 'rooms');
}

// ---------- amenities ----------
function admin_amenities(string $method): void
{
    $p = db();
    if ($method === 'POST') {
        foreach ($p->query('SELECT id FROM amenities')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $labels = json_encode(array_filter(array_map(fn($v) => trim((string)$v), (array)($_POST['label'][$id] ?? [])), 'strlen'), JSON_UNESCAPED_UNICODE);
            $p->prepare('UPDATE amenities SET confirmed = ?, label_json = ? WHERE id = ?')->execute([empty($_POST['confirmed'][$id]) ? 0 : 1, $labels, $id]);
        }
        flash('Amenities saved.');
        redirect('/admin/amenities');
    }
    $h = '<p class="muted">Only tick an amenity once the owner has confirmed it is real and working. Ticked items appear on the site.</p><form method="post" class="card">' . csrf_field();
    foreach ($p->query('SELECT * FROM amenities ORDER BY sort, id') as $a) {
        $m = json_decode($a['label_json'], true) ?: [];
        $h .= '<details class="am"><summary><label class="chk"><input type="checkbox" name="confirmed[' . (int)$a['id'] . ']" value="1"' . ($a['confirmed'] ? ' checked' : '') . '> '
            . e($a['icon']) . ' ' . e($m['en'] ?? $a['akey']) . '</label></summary><div class="ltabs">';
        $i = 0;
        $tabs = $panes = '';
        foreach (LANGS as $code => $meta) {
            $tabs .= '<button type="button" class="tab' . ($i === 0 ? ' on' : '') . '" data-t="' . $code . '">' . $code . '</button>';
            $panes .= '<div class="pane' . ($i === 0 ? ' on' : '') . '" data-p="' . $code . '"><input name="label[' . (int)$a['id'] . '][' . $code . ']" value="' . e($m[$code] ?? '') . '" dir="' . ($meta['rtl'] ? 'rtl' : 'ltr') . '"></div>';
            $i++;
        }
        $h .= '<div class="tabs">' . $tabs . '</div>' . $panes . '</div></details>';
    }
    $h .= '<div class="actions"><button class="btn">Save amenities</button></div></form>';
    admin_layout('Amenities', $h, 'amenities');
}

// ---------- content & settings ----------
function admin_content(string $method): void
{
    if ($method === 'POST') {
        try {
            foreach (['name_json' => 'name', 'tagline_json' => 'tagline', 'address_json' => 'address'] as $k => $f) {
                set_setting($k, collect_lang($f));
            }
            // validate everything first, then save
            foreach (['lat', 'lng'] as $k) {
                $v = trim((string)($_POST[$k] ?? ''));
                if ($v !== '' && !is_numeric($v)) {
                    throw new RuntimeException('Latitude/longitude must be numbers.');
                }
            }
            $site = trim((string)($_POST['site_url'] ?? ''));
            if ($site !== '' && !preg_match('#^https?://[^\s]+$#', $site)) {
                throw new RuntimeException('Site URL must start with http:// or https://');
            }
            $maps = trim((string)($_POST['maps_url'] ?? ''));
            if ($maps !== '' && !preg_match('#^https://[^\s]+$#', $maps)) {
                throw new RuntimeException('Maps link must start with https://');
            }
            $langs = array_values(array_filter((array)($_POST['langs'] ?? []), fn($l) => isset(LANGS[$l])));
            if (!$langs) {
                throw new RuntimeException('Enable at least one language.');
            }
            foreach (['rate_usd', 'rate_eur'] as $k) {
                $v = trim((string)($_POST[$k] ?? ''));
                if ($v !== '' && (!is_numeric($v) || (float)$v < 0 || (float)$v > 10000)) {
                    throw new RuntimeException('Exchange rates must be positive numbers.');
                }
            }
            $notify = trim((string)($_POST['notify_email'] ?? ''));
            if ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Notification email is not valid.');
            }
            $plink = trim((string)($_POST['promo_link'] ?? ''));
            if ($plink !== '' && !preg_match('#^(https://[^\s]+|/[^\s]*)$#', $plink)) {
                throw new RuntimeException('Promo link must start with https:// or /');
            }
            foreach (['phone', 'whatsapp', 'email', 'checkin', 'checkout', 'site_url', 'maps_url', 'metro_min', 'museum_min', 'lat', 'lng', 'notify_email', 'promo_link', 'rate_usd', 'rate_eur'] as $k) {
                set_setting($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, 300));
            }
            foreach (['promo_json' => 'promo', 'seo_title_json' => 'seo_title', 'seo_desc_json' => 'seo_desc'] as $k => $f) {
                set_setting($k, collect_lang($f));
            }
            set_setting('enabled_langs', implode(',', $langs));
            $def = (string)($_POST['default_lang'] ?? 'en');
            set_setting('default_lang', in_array($def, $langs, true) ? $def : $langs[0]);
            if (!empty($_FILES['hero']['name'])) {
                $old = setting('hero_image');
                set_setting('hero_image', save_upload($_FILES['hero']));
                if ($old) {
                    delete_upload($old);
                }
            } elseif (!empty($_POST['rm_hero'])) {
                delete_upload(setting('hero_image'));
                set_setting('hero_image', '');
            }
            flash('Saved.');
        } catch (Throwable $ex) {
            flash($ex->getMessage(), 'err');
        }
        redirect('/admin/content');
    }
    $in = fn(string $k, string $label, string $extra = '') => '<label>' . e($label) . '<input name="' . $k . '" value="' . e(setting($k)) . '" ' . $extra . '></label>';
    $h = '<form method="post" enctype="multipart/form-data" class="card">' . csrf_field()
        . '<h2>Hotel text</h2><label>Hotel name</label>' . lang_inputs('name', setting('name_json'))
        . '<label>Tagline (under the title)</label>' . lang_inputs('tagline', setting('tagline_json'), true)
        . '<label>Address</label>' . lang_inputs('address', setting('address_json'))
        . '<h2>Contact</h2><div class="row3">' . $in('phone', 'Phone', 'dir="ltr"') . $in('whatsapp', 'WhatsApp (digits, country code, e.g. 2010xxxxxxx)', 'dir="ltr"') . $in('email', 'Email', 'type="email" dir="ltr"') . '</div>'
        . '<div class="row3">' . $in('checkin', 'Check-in time (e.g. 14:00)') . $in('checkout', 'Check-out time (e.g. 12:00)') . $in('site_url', 'Public site URL (https://...)', 'dir="ltr"') . '</div>'
        . $in('notify_email', 'Email me when a booking request arrives (needs working mail on the server)', 'type="email" dir="ltr"')
        . '<h2>Promo banner</h2><p class="muted">Shown at the very top of every page. Leave empty to hide.</p>' . lang_inputs('promo', setting('promo_json'))
        . $in('promo_link', 'Promo link (optional, https://... or /en/faq)', 'dir="ltr"')
        . '<h2>Search engines (SEO) for the home page</h2><label>Page title</label>' . lang_inputs('seo_title', setting('seo_title_json'))
        . '<label>Description (about 150 characters)</label>' . lang_inputs('seo_desc', setting('seo_desc_json'), true)
        . '<h2>Map & distances</h2><div class="row4">' . $in('lat', 'Latitude', 'dir="ltr"') . $in('lng', 'Longitude', 'dir="ltr"') . $in('metro_min', 'Metro walk (min)', 'type="number"') . $in('museum_min', 'Museum walk (min)', 'type="number"') . '</div>'
        . $in('maps_url', 'Google Maps link', 'dir="ltr"')
        . '<h2>Price hint for foreign visitors</h2><p class="muted">Display only. Shows a small approximate price (for example ≈ $18) next to the EGP price. Enter how many EGP equal 1 USD / 1 EUR and update it now and then. Leave empty to hide.</p><div class="row3">'
        . $in('rate_usd', 'EGP per 1 USD', 'type="number" step="0.01" min="0"') . $in('rate_eur', 'EGP per 1 EUR', 'type="number" step="0.01" min="0"') . '</div>'
        . '<h2>Languages</h2><div class="langpick">';
    $enabled = enabled_langs();
    foreach (LANGS as $c => $meta) {
        $h .= '<label class="chk"><input type="checkbox" name="langs[]" value="' . $c . '"' . (in_array($c, $enabled, true) ? ' checked' : '') . '> ' . e($meta['name']) . '</label>';
    }
    $h .= '</div><label>Default language (when the visitor\'s language is not available)<select name="default_lang">';
    foreach (LANGS as $c => $meta) {
        $h .= '<option value="' . $c . '"' . (setting('default_lang') === $c ? ' selected' : '') . '>' . e($meta['name']) . '</option>';
    }
    $h .= '</select></label><h2>Hero image</h2>';
    if (setting('hero_image')) {
        $h .= '<div class="thumbs"><label class="th"><img src="' . e(setting('hero_image')) . '" alt=""><span><input type="checkbox" name="rm_hero" value="1"> remove</span></label></div>';
    }
    $h .= '<input type="file" name="hero" accept="image/jpeg,image/png,image/webp"><div class="actions"><button class="btn">Save</button></div></form>';
    admin_layout('Content & settings', $h, 'content');
}

// ---------- links ----------
function admin_links(string $method): void
{
    $p = db();
    if ($method === 'POST') {
        $bad = false;
        foreach ((array)($_POST['url'] ?? []) as $k => $url) {
            $url = trim((string)$url);
            if ($url !== '' && !preg_match('#^https://[^\s]+$#', $url)) {
                $bad = true;
                continue;
            }
            $p->prepare('UPDATE links SET url = ? WHERE lkey = ?')->execute([$url, (string)$k]);
        }
        flash($bad ? 'Some links were skipped: they must start with https://' : 'Links saved.', $bad ? 'err' : 'ok');
        redirect('/admin/links');
    }
    $h = '<p class="muted">Paste the public page link for each platform. Empty links are hidden from the site.</p><form method="post" class="card">' . csrf_field();
    foreach ($p->query('SELECT * FROM links ORDER BY sort') as $l) {
        $h .= '<label>' . e($l['label']) . '<input name="url[' . e($l['lkey']) . ']" value="' . e($l['url']) . '" placeholder="https://" dir="ltr"></label>';
    }
    $h .= '<div class="actions"><button class="btn">Save links</button></div></form>';
    admin_layout('Platforms & social', $h, 'links');
}

// ---------- gallery ----------
function admin_gallery(string $method): void
{
    $p = db();
    if ($method === 'POST') {
        try {
            if (($_POST['do'] ?? '') === 'delete') {
                $r = $p->prepare('SELECT path FROM gallery WHERE id = ?');
                $r->execute([(int)$_POST['id']]);
                if ($path = $r->fetchColumn()) {
                    delete_upload((string)$path);
                    $p->prepare('DELETE FROM gallery WHERE id = ?')->execute([(int)$_POST['id']]);
                }
                flash('Photo deleted.');
            } else {
                $n = 0;
                foreach ((array)($_FILES['photos']['name'] ?? []) as $i => $_) {
                    if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $path = save_upload(['tmp_name' => $_FILES['photos']['tmp_name'][$i], 'size' => $_FILES['photos']['size'][$i], 'error' => $_FILES['photos']['error'][$i]]);
                    $p->prepare('INSERT INTO gallery(path, sort) VALUES(?, ?)')->execute([$path, (int)time()]);
                    $n++;
                }
                flash($n ? "$n photo(s) uploaded." : 'Choose at least one photo.', $n ? 'ok' : 'err');
            }
        } catch (Throwable $ex) {
            flash($ex->getMessage(), 'err');
        }
        redirect('/admin/gallery');
    }
    $h = '<form method="post" enctype="multipart/form-data" class="card narrow">' . csrf_field()
        . '<label>Upload photos (JPG, PNG or WebP, max 8MB each)</label><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required><button class="btn">Upload</button></form><div class="gal-admin">';
    foreach ($p->query('SELECT * FROM gallery ORDER BY sort, id') as $g) {
        $h .= '<form method="post" class="th">' . csrf_field() . '<img src="' . e($g['path']) . '" alt=""><input type="hidden" name="id" value="' . (int)$g['id'] . '">'
            . '<button class="btn sm danger" name="do" value="delete" onclick="return confirm(\'Delete this photo?\')">Delete</button></form>';
    }
    admin_layout('Gallery', $h . '</div>', 'gallery');
}
