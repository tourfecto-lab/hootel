<?php
declare(strict_types=1);

// ---------- availability calendar ----------
function admin_calendar(): void
{
    $p = db();
    $ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');
    $first = new DateTime($ym . '-01');
    $days = (int)$first->format('t');
    $from = $first->format('Y-m-d');
    $to = (clone $first)->modify('+1 month')->format('Y-m-d');
    $prev = (clone $first)->modify('-1 month')->format('Y-m');
    $next = (clone $first)->modify('+1 month')->format('Y-m');

    $rooms = $p->query('SELECT * FROM rooms ORDER BY sort, id')->fetchAll();
    $reqs = $p->prepare("SELECT * FROM requests WHERE status IN ('confirmed','new') AND checkin < ? AND checkout > ? ORDER BY checkin");
    $reqs->execute([$to, $from]);
    $reqs = $reqs->fetchAll();
    $blocks = $p->prepare('SELECT * FROM blocks WHERE from_date < ? AND to_date >= ?');
    $blocks->execute([$to, $from]);
    $blocks = $blocks->fetchAll();

    $h = '<div class="filters"><a href="/admin/calendar?m=' . $prev . '">← ' . e($prev) . '</a><a class="on" href="/admin/calendar">' . e($first->format('F Y')) . '</a><a href="/admin/calendar?m=' . $next . '">' . e($next) . ' →</a></div>'
        . '<div class="card"><div class="cal-scroll"><table class="cal"><tr><th>Room</th>';
    for ($d = 1; $d <= $days; $d++) {
        $dow = (int)(new DateTime("$ym-" . sprintf('%02d', $d)))->format('N');
        $h .= '<th class="' . ($dow >= 5 ? 'we' : '') . '">' . $d . '</th>';
    }
    $h .= '</tr>';
    foreach ($rooms as $r) {
        $h .= '<tr><td class="rn">' . e(tr_json($r['name_json'], 'en')) . '<small>' . (int)$r['qty'] . ($r['unit'] === 'bed' ? ' dorm' : ' rooms') . '</small></td>';
        for ($d = 1; $d <= $days; $d++) {
            $day = sprintf('%s-%02d', $ym, $d);
            $conf = $pend = 0;
            foreach ($reqs as $q) {
                if ($q['room_slug'] === $r['slug'] && $q['checkin'] <= $day && $q['checkout'] > $day) {
                    $units = $r['unit'] === 'bed' ? (int)$q['guests'] : 1;
                    if ($q['status'] === 'confirmed') {
                        $conf += $units;
                    } else {
                        $pend += $units;
                    }
                }
            }
            $closed = false;
            foreach ($blocks as $b) {
                if ($b['room_slug'] === $r['slug'] && $b['from_date'] <= $day && $b['to_date'] >= $day) {
                    $closed = true;
                }
            }
            $cls = $closed ? 'closed' : ($conf >= (int)$r['qty'] && $r['unit'] === 'room' ? 'full' : ($conf > 0 ? 'part' : ''));
            $txt = $closed ? '✕' : ($conf > 0 ? (string)$conf : '');
            if ($pend > 0 && !$closed) {
                $txt .= '<i title="' . $pend . ' pending">•</i>';
            }
            $h .= '<td class="c ' . $cls . '">' . $txt . '</td>';
        }
        $h .= '</tr>';
    }
    $h .= '</table></div><p class="muted">Number = confirmed bookings that night · • = pending requests · ✕ = closed · red = fully booked.</p></div>';

    $h .= '<div class="card"><h2>Bookings this month</h2><table><tr><th>Guest</th><th>Room</th><th>Dates</th><th>Guests</th><th>Status</th></tr>';
    $any = false;
    foreach ($reqs as $q) {
        $any = true;
        $h .= '<tr><td>' . e($q['name']) . '</td><td>' . e($q['room_slug']) . '</td><td>' . e($q['checkin']) . ' → ' . e($q['checkout']) . '</td><td>' . (int)$q['guests']
            . '</td><td><span class="pill ' . e($q['status']) . '">' . e(STATUSES[$q['status']] ?? $q['status']) . '</span></td></tr>';
    }
    $h .= $any ? '' : '<tr><td colspan="5" class="muted">No bookings this month.</td></tr>';
    $h .= '</table></div>';
    admin_layout('Availability calendar', $h, 'calendar');
}

// ---------- seasonal prices & closures ----------
function valid_range(string $a, string $b): bool
{
    $d1 = DateTime::createFromFormat('Y-m-d', $a);
    $d2 = DateTime::createFromFormat('Y-m-d', $b);
    return $d1 && $d2 && $d1->format('Y-m-d') === $a && $d2->format('Y-m-d') === $b && $d2 >= $d1;
}

function admin_pricing(string $method): void
{
    $p = db();
    $rooms = $p->query('SELECT slug, name_json FROM rooms ORDER BY sort, id')->fetchAll();
    $slugs = array_column($rooms, 'slug');
    if ($method === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        $slug = (string)($_POST['room'] ?? '');
        $a = (string)($_POST['from'] ?? '');
        $b = (string)($_POST['to'] ?? '');
        if ($do === 'del_rule') {
            $p->prepare('DELETE FROM price_rules WHERE id = ?')->execute([(int)$_POST['id']]);
            flash('Seasonal price removed.');
        } elseif ($do === 'del_block') {
            $p->prepare('DELETE FROM blocks WHERE id = ?')->execute([(int)$_POST['id']]);
            flash('Closure removed.');
        } elseif (!in_array($slug, $slugs, true) || !valid_range($a, $b)) {
            flash('Pick a room and a valid date range (from ≤ to).', 'err');
        } elseif ($do === 'add_rule') {
            $price = (int)($_POST['price'] ?? 0);
            if ($price <= 0) {
                flash('Price must be greater than 0.', 'err');
            } else {
                $p->prepare('INSERT INTO price_rules(room_slug, from_date, to_date, price, label) VALUES(?,?,?,?,?)')->execute([$slug, $a, $b, $price, mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80)]);
                flash('Seasonal price added. The newest rule wins if ranges overlap.');
            }
        } elseif ($do === 'add_block') {
            $p->prepare('INSERT INTO blocks(room_slug, from_date, to_date, note) VALUES(?,?,?,?)')->execute([$slug, $a, $b, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 120)]);
            flash('Room type closed for those dates.');
        }
        redirect('/admin/pricing');
    }
    $opts = '';
    foreach ($rooms as $r) {
        $opts .= '<option value="' . e($r['slug']) . '">' . e(tr_json($r['name_json'], 'en')) . '</option>';
    }
    $form = fn(string $do, string $extra, string $btn) => '<form method="post" class="row4 card-in">' . csrf_field() . '<input type="hidden" name="do" value="' . $do . '">'
        . '<label>Room<select name="room">' . $opts . '</select></label><label>From<input type="date" name="from" required></label><label>To (inclusive)<input type="date" name="to" required></label>'
        . $extra . '<button class="btn">' . $btn . '</button></form>';

    $h = '<section class="card"><h2>Seasonal prices</h2><p class="muted">Override the nightly price for a date range (high season, Eid, winter...). Guests see the right total in the booking form.</p>'
        . $form('add_rule', '<label>Price (EGP)<input type="number" name="price" min="1" required></label><label>Label<input name="label" placeholder="High season"></label>', 'Add price')
        . '<table><tr><th>Room</th><th>From</th><th>To</th><th>Price</th><th>Label</th><th></th></tr>';
    $n = 0;
    foreach ($p->query('SELECT * FROM price_rules ORDER BY from_date DESC') as $r) {
        $n++;
        $h .= '<tr><td>' . e($r['room_slug']) . '</td><td>' . e($r['from_date']) . '</td><td>' . e($r['to_date']) . '</td><td>' . number_format((int)$r['price']) . '</td><td>' . e($r['label'])
            . '</td><td><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="del_rule"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn sm danger">Remove</button></form></td></tr>';
    }
    $h .= ($n ? '' : '<tr><td colspan="6" class="muted">No seasonal prices yet.</td></tr>') . '</table></section>';

    $h .= '<section class="card"><h2>Closures</h2><p class="muted">Close a room type for dates (renovation, owner block, fully booked on another platform). Closed dates reject new requests.</p>'
        . $form('add_block', '<label>Note<input name="note" placeholder="Maintenance"></label>', 'Close dates')
        . '<table><tr><th>Room</th><th>From</th><th>To</th><th>Note</th><th></th></tr>';
    $n = 0;
    foreach ($p->query('SELECT * FROM blocks ORDER BY from_date DESC') as $r) {
        $n++;
        $h .= '<tr><td>' . e($r['room_slug']) . '</td><td>' . e($r['from_date']) . '</td><td>' . e($r['to_date']) . '</td><td>' . e($r['note'])
            . '</td><td><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="del_block"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn sm danger">Remove</button></form></td></tr>';
    }
    $h .= ($n ? '' : '<tr><td colspan="5" class="muted">No closures.</td></tr>') . '</table></section>';
    admin_layout('Seasons & closures', $h, 'pricing');
}

// ---------- editable content items (FAQ, policies, nearby, guest reviews) ----------
function item_defs(): array
{
    return [
        'faq' => ['title' => 'FAQ', 'help' => 'Questions guests ask. Appears on the FAQ page and in Google rich results. Untranslated languages fall back to English.',
            'fields' => [['q', 'Question', 'lang'], ['a', 'Answer', 'langarea']]],
        'policy' => ['title' => 'Policies & house rules', 'help' => 'Check-in rules, cancellation, quiet hours, ID requirements, children, pets...',
            'fields' => [['title', 'Title', 'lang'], ['text', 'Text', 'langarea']]],
        'attraction' => ['title' => 'Nearby places', 'help' => 'Landmarks, metro, restaurants, with travel time. Shown on the home and contact pages.',
            'fields' => [['name', 'Place', 'lang'], ['note', 'Short note (optional)', 'lang'], ['min', 'Minutes', 'number'], ['mode', 'How', 'mode']]],
        'testimonial' => ['title' => 'Guest reviews', 'help' => 'Only add REAL quotes from real guests (with permission). Do not invent or edit reviews. Hidden until you add one.',
            'fields' => [['author', 'Guest name', 'text'], ['country', 'Country', 'text'], ['source', 'Source (e.g. Google, Booking)', 'text'], ['text', 'Quote', 'langarea']]],
    ];
}

function admin_items(string $method, string $type): void
{
    $defs = item_defs();
    $def = $defs[$type];
    $p = db();
    $route = ['faq' => 'faq', 'policy' => 'policies', 'attraction' => 'nearby', 'testimonial' => 'testimonials'][$type];
    if ($method === 'POST') {
        $do = (string)($_POST['do'] ?? 'save');
        $id = (int)($_POST['id'] ?? 0);
        if ($do === 'add') {
            $max = (int)$p->query("SELECT COALESCE(MAX(sort),0) FROM items WHERE type = " . $p->quote($type))->fetchColumn();
            $p->prepare('INSERT INTO items(type, sort, active, data_json) VALUES(?,?,0,?)')->execute([$type, $max + 1, '{}']);
            flash('New item added (hidden until you tick "Show" and save).');
        } elseif ($do === 'delete') {
            $p->prepare('DELETE FROM items WHERE id = ? AND type = ?')->execute([$id, $type]);
            flash('Deleted.');
        } else {
            $data = [];
            foreach ($def['fields'] as [$k, , $kind]) {
                if ($kind === 'lang' || $kind === 'langarea') {
                    $data[$k] = json_decode(collect_lang('f_' . $k), true) ?: [];
                } elseif ($kind === 'number') {
                    $data[$k] = max(0, min(999, (int)($_POST['f_' . $k] ?? 0)));
                } elseif ($kind === 'mode') {
                    $m = (string)($_POST['f_' . $k] ?? 'walk');
                    $data[$k] = in_array($m, ['walk', 'metro', 'taxi'], true) ? $m : 'walk';
                } else {
                    $data[$k] = mb_substr(trim((string)($_POST['f_' . $k] ?? '')), 0, 300);
                }
            }
            $p->prepare('UPDATE items SET data_json = ?, active = ?, sort = ? WHERE id = ? AND type = ?')
                ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), empty($_POST['active']) ? 0 : 1, (int)($_POST['sort'] ?? 0), $id, $type]);
            flash('Saved.');
        }
        redirect('/admin/' . $route);
    }
    $h = '<p class="muted">' . e($def['help']) . '</p>';
    foreach (items($type, false) as $it) {
        $d = $it['d'];
        $h .= '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$it['id'] . '">'
            . '<div class="row4"><label class="chk"><input type="checkbox" name="active" value="1"' . ($it['active'] ? ' checked' : '') . '> Show on site</label>'
            . '<label>Order<input type="number" name="sort" value="' . (int)$it['sort'] . '"></label></div>';
        foreach ($def['fields'] as [$k, $label, $kind]) {
            $h .= '<label>' . e($label) . '</label>';
            if ($kind === 'lang' || $kind === 'langarea') {
                $h .= lang_inputs('f_' . $k, json_encode($d[$k] ?? []), $kind === 'langarea');
            } elseif ($kind === 'number') {
                $h .= '<input type="number" name="f_' . $k . '" min="0" max="999" value="' . (int)($d[$k] ?? 0) . '">';
            } elseif ($kind === 'mode') {
                $h .= '<select name="f_' . $k . '">';
                foreach (['walk' => 'On foot', 'metro' => 'By metro', 'taxi' => 'By taxi'] as $mv => $ml) {
                    $h .= '<option value="' . $mv . '"' . (($d[$k] ?? 'walk') === $mv ? ' selected' : '') . '>' . $ml . '</option>';
                }
                $h .= '</select>';
            } else {
                $h .= '<input name="f_' . $k . '" value="' . e($d[$k] ?? '') . '">';
            }
        }
        $h .= '<div class="actions"><button class="btn">Save</button><button class="btn danger" name="do" value="delete" onclick="return confirm(\'Delete this item?\')">Delete</button></div></form>';
    }
    $h .= '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="add"><button class="btn">+ Add new</button></form>';
    admin_layout($def['title'], $h, $route);
}

// ---------- users ----------
function admin_users(string $method): void
{
    $p = db();
    $me = (int)$_SESSION['admin_id'];
    if ($method === 'POST') {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'add') {
            $u = trim((string)($_POST['username'] ?? ''));
            $pw = (string)($_POST['password'] ?? '');
            $role = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
            if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $u) || strlen($pw) < 10) {
                flash('Username: 3-32 letters/numbers. Password: at least 10 characters.', 'err');
            } else {
                try {
                    $p->prepare('INSERT INTO admins(username, pass_hash, role) VALUES(?,?,?)')->execute([$u, password_hash($pw, PASSWORD_DEFAULT), $role]);
                    flash("User $u created.");
                } catch (PDOException $ex) {
                    flash('That username already exists.', 'err');
                }
            }
        } elseif ($do === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $admins = (int)$p->query("SELECT COUNT(*) FROM admins WHERE role = 'admin'")->fetchColumn();
            $row = $p->prepare('SELECT role FROM admins WHERE id = ?');
            $row->execute([$id]);
            $role = $row->fetchColumn();
            if ($id === $me) {
                flash('You cannot delete your own account.', 'err');
            } elseif ($role === 'admin' && $admins <= 1) {
                flash('Keep at least one admin.', 'err');
            } else {
                $p->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
                flash('User deleted.');
            }
        } elseif ($do === 'reset') {
            $pw = (string)($_POST['password'] ?? '');
            if (strlen($pw) < 10) {
                flash('Password must be at least 10 characters.', 'err');
            } else {
                $p->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), (int)($_POST['id'] ?? 0)]);
                flash('Password reset.');
            }
        }
        redirect('/admin/users');
    }
    $h = '<section class="card"><h2>Team</h2><p class="muted"><b>Admin</b> can change everything. <b>Staff</b> can only handle booking requests and the calendar.</p><table><tr><th>User</th><th>Role</th><th>Reset password</th><th></th></tr>';
    foreach ($p->query('SELECT id, username, role FROM admins ORDER BY id') as $a) {
        $h .= '<tr><td>' . e($a['username']) . ((int)$a['id'] === $me ? ' <span class="muted">(you)</span>' : '') . '</td><td>' . e($a['role'])
            . '</td><td><form method="post" class="reqact">' . csrf_field() . '<input type="hidden" name="do" value="reset"><input type="hidden" name="id" value="' . (int)$a['id'] . '"><input type="password" name="password" placeholder="New password (10+)" autocomplete="new-password"><button class="btn sm">Set</button></form></td>'
            . '<td><form method="post">' . csrf_field() . '<input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="' . (int)$a['id'] . '"><button class="btn sm danger" onclick="return confirm(\'Delete this user?\')">Delete</button></form></td></tr>';
    }
    $h .= '</table></section><form method="post" class="card narrow">' . csrf_field() . '<h2>Add user</h2><input type="hidden" name="do" value="add">'
        . '<label>Username<input name="username" required autocomplete="off"></label><label>Password (10+ characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>'
        . '<label>Role<select name="role"><option value="staff">Staff</option><option value="admin">Admin</option></select></label><button class="btn">Create</button></form>';
    admin_layout('Users', $h, 'users');
}

// ---------- activity log ----------
function admin_audit(): void
{
    $h = '<div class="card"><table><tr><th>When (UTC)</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr>';
    foreach (db()->query('SELECT * FROM audit ORDER BY id DESC LIMIT 200') as $r) {
        $h .= '<tr><td>' . e($r['at']) . '</td><td>' . e($r['username']) . '</td><td>' . e($r['action']) . '</td><td>' . e($r['detail']) . '</td><td>' . e($r['ip']) . '</td></tr>';
    }
    admin_layout('Activity log', $h . '</table></div>', 'audit');
}

// ---------- backup ----------
function admin_backup(string $method): void
{
    if ($method === 'POST') {
        $tmp = tempnam(sys_get_temp_dir(), 'kpb');
        @unlink($tmp);
        db()->exec("VACUUM INTO " . db()->quote($tmp));
        audit('backup download');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="kings-palace-backup-' . date('Ymd-His') . '.sqlite"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    admin_layout('Backup', '<div class="card narrow"><p>Download a full copy of the database (rooms, prices, texts, booking requests, users).</p>'
        . '<p class="muted">Photos are stored in <code>public/uploads/</code>; copy that folder too. Keep backups private: they contain guest phone numbers.</p>'
        . '<form method="post">' . csrf_field() . '<button class="btn">Download database backup</button></form></div>', 'backup');
}
