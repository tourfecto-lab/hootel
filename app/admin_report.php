<?php
declare(strict_types=1);

// ---------- helpers shared by requests, dashboard and report ----------
function effective_attrib(array $r): string
{
    if ($r['source'] !== 'direct') {
        return 'ota';
    }
    if (in_array($r['attrib'], ['agency', 'organic'], true)) {
        return $r['attrib'];
    }
    return $r['utm_source'] !== '' ? 'agency' : 'organic';
}

function channel_label(array $r): string
{
    if ($r['source'] !== 'direct') {
        return PLATFORMS[$r['source']] ?? $r['source'];
    }
    return $r['utm_source'] !== '' ? $r['utm_source'] : ($r['referrer'] !== '' ? $r['referrer'] : 'direct');
}

function attrib_select(array $r): string
{
    if ($r['source'] !== 'direct') {
        return '';
    }
    $cur = in_array($r['attrib'], ['agency', 'organic'], true) ? $r['attrib'] : '';
    $auto = $r['utm_source'] !== '' ? 'agency (tagged link)' : 'organic';
    return '<select name="attrib" title="Who brought this guest?"><option value="">Auto: ' . e($auto) . '</option>'
        . '<option value="agency"' . ($cur === 'agency' ? ' selected' : '') . '>Brought by agency</option>'
        . '<option value="organic"' . ($cur === 'organic' ? ' selected' : '') . '>Organic / own</option></select>';
}

function wa_digits(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    if (str_starts_with(trim($phone), '+')) {
        return $d;
    }
    if (str_starts_with($d, '00')) {
        return substr($d, 2);
    }
    if (str_starts_with($d, '0')) { // local Egyptian number
        return '20' . substr($d, 1);
    }
    return $d;
}

/** Ready-to-send WhatsApp text in the guest's own language. $kind: confirm | unavailable */
function wa_message(array $r, string $kind): string
{
    $lang = isset(LANGS[$r['lang']]) ? $r['lang'] : 'en';
    $T = [
        'confirm' => [
            'ar' => 'أهلًا {name}، تم تأكيد حجزك في {hotel}: {room}، من {in} إلى {out} ({nights} ليالي)، {guests} أفراد. الإجمالي التقديري: {total} جنيه. في انتظارك! العنوان: {addr}',
            'en' => 'Hello {name}, your booking at {hotel} is confirmed: {room}, {in} to {out} ({nights} nights), {guests} guest(s). Estimated total: {total} EGP. We look forward to welcoming you! Address: {addr}',
            'de' => 'Hallo {name}, Ihre Buchung im {hotel} ist bestätigt: {room}, {in} bis {out} ({nights} Nächte), {guests} Gast/Gäste. Geschätzter Gesamtpreis: {total} EGP. Wir freuen uns auf Sie! Adresse: {addr}',
            'fr' => 'Bonjour {name}, votre réservation au {hotel} est confirmée : {room}, du {in} au {out} ({nights} nuits), {guests} voyageur(s). Total estimé : {total} EGP. Nous avons hâte de vous accueillir ! Adresse : {addr}',
            'it' => 'Ciao {name}, la tua prenotazione al {hotel} è confermata: {room}, dal {in} al {out} ({nights} notti), {guests} ospite/i. Totale stimato: {total} EGP. Non vediamo l’ora di accoglierti! Indirizzo: {addr}',
            'es' => 'Hola {name}, tu reserva en {hotel} está confirmada: {room}, del {in} al {out} ({nights} noches), {guests} huésped(es). Total estimado: {total} EGP. ¡Te esperamos! Dirección: {addr}',
            'ru' => 'Здравствуйте, {name}! Ваша бронь в {hotel} подтверждена: {room}, {in} — {out} ({nights} ноч.), гостей: {guests}. Ориентировочная сумма: {total} EGP. Ждём вас! Адрес: {addr}',
            'zh' => '{name} 您好，您在 {hotel} 的预订已确认：{room}，{in} 至 {out}（{nights} 晚），{guests} 位。预估总价：{total} EGP。期待您的光临！地址：{addr}',
        ],
        'unavailable' => [
            'ar' => 'أهلًا {name}، شكرًا على طلبك لـ {room} من {in} إلى {out}. للأسف النوع ده مش متاح في التواريخ دي. ينفع نجرب تواريخ تانية أو نوع غرفة تاني؟',
            'en' => 'Hello {name}, thank you for your request for {room}, {in} to {out}. Unfortunately that room type is not available on those dates. Would other dates or another room type work for you?',
            'de' => 'Hallo {name}, vielen Dank für Ihre Anfrage für {room}, {in} bis {out}. Leider ist dieser Zimmertyp an diesen Tagen nicht verfügbar. Passen andere Daten oder ein anderer Zimmertyp?',
            'fr' => 'Bonjour {name}, merci pour votre demande de {room}, du {in} au {out}. Malheureusement ce type de chambre n’est pas disponible à ces dates. D’autres dates ou un autre type de chambre vous conviendraient-ils ?',
            'it' => 'Ciao {name}, grazie per la richiesta di {room}, dal {in} al {out}. Purtroppo questo tipo di camera non è disponibile in quelle date. Vanno bene altre date o un altro tipo di camera?',
            'es' => 'Hola {name}, gracias por tu solicitud de {room}, del {in} al {out}. Lamentablemente ese tipo de habitación no está disponible en esas fechas. ¿Te sirven otras fechas u otro tipo de habitación?',
            'ru' => 'Здравствуйте, {name}! Спасибо за запрос на {room}, {in} — {out}. К сожалению, этот тип номера на эти даты недоступен. Подойдут другие даты или другой тип номера?',
            'zh' => '{name} 您好，感谢您申请 {room}（{in} 至 {out}）。很抱歉该房型在这些日期不可订。其他日期或其他房型是否合适？',
        ],
    ];
    $room = db()->prepare('SELECT name_json FROM rooms WHERE slug = ?');
    $room->execute([$r['room_slug']]);
    $roomName = tr_json((string)$room->fetchColumn(), $lang) ?: $r['room_slug'];
    $nights = max(1, (int)(new DateTime($r['checkin']))->diff(new DateTime($r['checkout']))->days);
    return strtr($T[$kind][$lang], [
        '{name}' => $r['name'], '{hotel}' => tr_json(setting('name_json'), $lang), '{room}' => $roomName, '{in}' => $r['checkin'], '{out}' => $r['checkout'],
        '{nights}' => (string)$nights, '{guests}' => (string)$r['guests'], '{total}' => number_format((int)$r['est_total']),
        '{addr}' => setting('maps_url') !== '' ? setting('maps_url') : tr_json(setting('address_json'), $lang),
    ]);
}

function wa_buttons(array $r): string
{
    $d = wa_digits($r['phone']);
    if ($d === '' || $r['source'] !== 'direct') {
        return '';
    }
    $link = fn(string $kind, string $label) => '<a class="btn sm gold" target="_blank" rel="noopener" href="https://wa.me/' . e($d) . '?text=' . e(rawurlencode(wa_message($r, $kind))) . '">' . e($label) . '</a>';
    return $link('confirm', 'WhatsApp: confirmed') . $link('unavailable', 'WhatsApp: not available');
}

// ---------- dashboard marketing block ----------
function dashboard_marketing(): string
{
    $p = db();
    $since = "day >= date('now','-30 day')";
    $sessions = $p->query("SELECT label, SUM(n) n FROM events WHERE type='session' AND $since GROUP BY label ORDER BY n DESC LIMIT 8")->fetchAll();
    $clicks = $p->query("SELECT type, SUM(n) n FROM events WHERE type IN ('whatsapp','call','email','link','directions') AND $since GROUP BY type ORDER BY n DESC")->fetchAll();
    $li = fn(array $rows, string $k) => $rows ? implode('', array_map(fn($r) => '<li><span>' . e($r[$k]) . '</span><b>' . (int)$r['n'] . '</b></li>', $rows)) : '<li class="muted">No data yet.</li>';
    return '<div class="cols"><section class="card"><h2>Where visitors come from (30 days)</h2><ul class="kv">' . $li($sessions, 'label') . '</ul>'
        . '<p class="muted">Tag your ad and post links like <code>?utm_source=facebook&amp;utm_campaign=winter</code> to see each campaign here.</p></section>'
        . '<section class="card"><h2>Contact clicks (30 days)</h2><ul class="kv">' . $li($clicks, 'type') . '</ul></section></div>';
}

// ---------- monthly report with commission calculator ----------
function admin_report(string $method): void
{
    $p = db();
    if ($method === 'POST') {
        foreach (['comm_agency', 'comm_organic', 'comm_ota'] as $k) {
            $v = (float)($_POST[$k] ?? 0);
            set_setting($k, (string)max(0, min(100, $v)));
        }
        flash('Commission rates saved.');
        redirect('/admin/report?m=' . urlencode(preg_match('/^\d{4}-\d{2}$/', (string)($_POST['m'] ?? '')) ? (string)$_POST['m'] : date('Y-m')));
    }
    $ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');
    $first = new DateTime($ym . '-01');
    $from = $first->format('Y-m-d');
    $to = (clone $first)->modify('+1 month')->format('Y-m-d');
    $prev = (clone $first)->modify('-1 month')->format('Y-m');
    $next = (clone $first)->modify('+1 month')->format('Y-m');
    $pct = ['agency' => (float)(setting('comm_agency', '30')), 'organic' => (float)setting('comm_organic', '0'), 'ota' => (float)setting('comm_ota', '0')];

    $rows = $p->prepare("SELECT * FROM requests WHERE status = 'confirmed' AND checkin >= ? AND checkin < ? ORDER BY checkin");
    $rows->execute([$from, $to]);
    $rows = $rows->fetchAll();
    $tot = ['agency' => [0, 0], 'organic' => [0, 0], 'ota' => [0, 0]]; // [count, EGP]
    $missing = 0;
    foreach ($rows as $r) {
        $c = effective_attrib($r);
        $tot[$c][0]++;
        $tot[$c][1] += (int)$r['est_total'];
        $missing += (int)$r['est_total'] === 0 ? 1 : 0;
    }
    $comm = fn(string $c) => $tot[$c][1] * $pct[$c] / 100;
    $commTotal = $comm('agency') + $comm('organic') + $comm('ota');

    $reqAll = $p->prepare('SELECT status, COUNT(*) n FROM requests WHERE source = "direct" AND created_at >= ? AND created_at < ? GROUP BY status');
    $reqAll->execute([$from, $to]);
    $byStatus = array_column($reqAll->fetchAll(), 'n', 'status');
    $reqTotal = array_sum($byStatus);
    $conv = $reqTotal ? round(($byStatus['confirmed'] ?? 0) / $reqTotal * 100) : 0;

    $ev = $p->prepare("SELECT type, label, SUM(n) n FROM events WHERE day >= ? AND day < ? GROUP BY type, label ORDER BY n DESC");
    $ev->execute([$from, $to]);
    $events = $ev->fetchAll();
    $sess = array_filter($events, fn($e) => $e['type'] === 'session');
    $clicks = [];
    foreach ($events as $e) {
        if ($e['type'] !== 'session' && $e['type'] !== 'request') {
            $clicks[$e['type']] = ($clicks[$e['type']] ?? 0) + (int)$e['n'];
        }
    }
    $visits = $p->prepare('SELECT lang, SUM(n) n FROM visits WHERE day >= ? AND day < ? GROUP BY lang ORDER BY n DESC');
    $visits->execute([$from, $to]);
    $visits = $visits->fetchAll();

    $li = fn(array $rows, callable $l, callable $n) => $rows ? implode('', array_map(fn($r) => '<li><span>' . e($l($r)) . '</span><b>' . e((string)$n($r)) . '</b></li>', $rows)) : '<li class="muted">No data.</li>';
    $money = fn($v) => number_format((float)$v);

    $h = '<div class="filters noprint"><a href="/admin/report?m=' . $prev . '">← ' . e($prev) . '</a><a class="on" href="/admin/report?m=' . e($ym) . '">' . e($first->format('F Y')) . '</a><a href="/admin/report?m=' . $next . '">' . e($next) . ' →</a>'
        . '<a class="right" href="/admin/report.csv?m=' . e($ym) . '">⬇ CSV</a><a href="#" onclick="window.print();return false">🖨 Print / PDF</a></div>'
        . '<div class="stats">'
        . '<div class="stat"><b>' . array_sum(array_map(fn($e) => (int)$e['n'], $sess)) . '</b><span>Visitor sessions</span></div>'
        . '<div class="stat"><b>' . $reqTotal . '</b><span>Website booking requests</span></div>'
        . '<div class="stat"><b>' . $conv . '%</b><span>Requests confirmed</span></div>'
        . '<div class="stat"><b>' . count($rows) . '</b><span>Confirmed stays (check-in this month)</span></div>'
        . '<div class="stat"><b>' . $money($tot['agency'][1] + $tot['organic'][1] + $tot['ota'][1]) . '</b><span>Est. revenue (EGP)</span></div></div>';

    $h .= '<div class="cols"><section class="card"><h2>Traffic sources</h2><ul class="kv">' . $li(array_values($sess), fn($r) => $r['label'], fn($r) => $r['n']) . '</ul></section>'
        . '<section class="card"><h2>Visitors by language</h2><ul class="kv">' . $li($visits, fn($r) => LANGS[$r['lang']]['name'] ?? $r['lang'], fn($r) => $r['n']) . '</ul>'
        . '<h2 style="margin-top:14px">Contact clicks</h2><ul class="kv">' . $li(array_map(fn($k, $v) => ['t' => $k, 'n' => $v], array_keys($clicks), $clicks), fn($r) => $r['t'], fn($r) => $r['n']) . '</ul></section></div>';

    $h .= '<section class="card"><h2>Commission calculator</h2><p class="muted">Based on confirmed stays with check-in in this month and the estimated total on each booking. Change a booking\'s owner (agency / organic) in Booking requests.</p>'
        . '<form method="post" class="row4 noprint">' . csrf_field() . '<input type="hidden" name="m" value="' . e($ym) . '">'
        . '<label>Agency-brought direct bookings %<input type="number" step="0.5" min="0" max="100" name="comm_agency" value="' . e((string)$pct['agency']) . '"></label>'
        . '<label>Other direct bookings %<input type="number" step="0.5" min="0" max="100" name="comm_organic" value="' . e((string)$pct['organic']) . '"></label>'
        . '<label>Platform (OTA) bookings %<input type="number" step="0.5" min="0" max="100" name="comm_ota" value="' . e((string)$pct['ota']) . '"></label><button class="btn">Save rates</button></form>'
        . '<table><tr><th>Category</th><th>Stays</th><th>Revenue (EGP)</th><th>Rate</th><th>Commission (EGP)</th></tr>';
    foreach (['agency' => 'Brought by agency (direct)', 'organic' => 'Other direct', 'ota' => 'Platforms (OTA)'] as $k => $label) {
        $h .= '<tr><td>' . e($label) . '</td><td>' . $tot[$k][0] . '</td><td>' . $money($tot[$k][1]) . '</td><td>' . e((string)$pct[$k]) . '%</td><td>' . $money($comm($k)) . '</td></tr>';
    }
    $h .= '<tr><th>Total</th><th>' . count($rows) . '</th><th>' . $money($tot['agency'][1] + $tot['organic'][1] + $tot['ota'][1]) . '</th><th></th><th>' . $money($commTotal) . '</th></tr></table>'
        . ($missing ? '<p class="muted">⚠ ' . $missing . ' booking(s) have no amount (for example imported platform bookings). Their revenue is counted as 0.</p>' : '') . '</section>';

    $h .= '<section class="card"><h2>Confirmed stays</h2><table><tr><th>Guest</th><th>Room</th><th>Dates</th><th>Channel</th><th>Owner</th><th>Est. (EGP)</th></tr>';
    foreach ($rows as $r) {
        $h .= '<tr><td>' . e($r['name']) . '</td><td>' . e($r['room_slug']) . '</td><td>' . e($r['checkin']) . ' → ' . e($r['checkout']) . '</td><td>' . e(channel_label($r)) . '</td><td>' . e(effective_attrib($r)) . '</td><td>' . $money($r['est_total']) . '</td></tr>';
    }
    $h .= (count($rows) ? '' : '<tr><td colspan="6" class="muted">No confirmed stays this month.</td></tr>') . '</table></section>'
        . '<p class="muted">Figures are estimates from the website and imported calendars. Actual payments are settled directly between the guest and the hotel.</p>';
    admin_layout('Monthly report', $h, 'report');
}

function admin_report_csv(): void
{
    $ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : date('Y-m');
    $first = new DateTime($ym . '-01');
    $st = db()->prepare("SELECT * FROM requests WHERE status = 'confirmed' AND checkin >= ? AND checkin < ? ORDER BY checkin");
    $st->execute([$first->format('Y-m-d'), (clone $first)->modify('+1 month')->format('Y-m-d')]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-' . $ym . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['guest', 'room', 'checkin', 'checkout', 'guests', 'channel', 'owner', 'est_total_egp']);
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v;
    foreach ($st as $r) {
        fputcsv($o, [$safe($r['name']), $r['room_slug'], $r['checkin'], $r['checkout'], $r['guests'], $safe(channel_label($r)), effective_attrib($r), $r['est_total']]);
    }
    fclose($o);
}
