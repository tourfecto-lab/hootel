<?php
declare(strict_types=1);

const ERR_KEYS = ['err_generic', 'err_dates', 'err_rate', 'not_available'];

function base_url(): string
{
    $u = rtrim(setting('site_url'), '/');
    if ($u !== '') {
        return $u;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host = preg_replace('/[^a-zA-Z0-9.:\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https://' : 'http://') . $host;
}

function detect_lang(): string
{
    $en = enabled_langs();
    foreach (explode(',', strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $part) {
        $code = substr(trim(explode(';', $part)[0]), 0, 2);
        if (in_array($code, $en, true)) {
            return $code;
        }
    }
    $d = setting('default_lang', 'en');
    return in_array($d, $en, true) ? $d : $en[0];
}

function track_visit(string $lang): void
{
    if (!empty($_SESSION['admin_id']) || preg_match('/bot|crawl|spider|slurp|preview/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
        return;
    }
    db()->prepare("INSERT INTO visits(day, lang, n) VALUES(date('now'), ?, 1) ON CONFLICT(day, lang) DO UPDATE SET n = n + 1")->execute([$lang]);
}

function record_event(string $type, string $label = ''): void
{
    db()->prepare("INSERT INTO events(day, type, label, n) VALUES(date('now'), ?, ?, 1) ON CONFLICT(day, type, label) DO UPDATE SET n = n + 1")
        ->execute([$type, mb_substr($label, 0, 40)]);
}

/** Remember where this visitor came from (utm tags first, then the referring site) for the whole session. */
function capture_source(): void
{
    if (isset($_SESSION['src']) || preg_match('/bot|crawl|spider|slurp|preview/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
        return;
    }
    $clean = fn($v) => preg_replace('/[^a-z0-9._\-]/i', '', mb_substr((string)$v, 0, 40));
    $utm = $clean($_GET['utm_source'] ?? '');
    $ref = '';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $h = strtolower((string)parse_url((string)$_SERVER['HTTP_REFERER'], PHP_URL_HOST));
        $own = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        if ($h !== '' && $h !== $own) {
            $ref = preg_replace('/^www\./', '', $h);
        }
    }
    $map = ['facebook' => 'facebook', 'fb.' => 'facebook', 'instagram' => 'instagram', 'google.' => 'google', 'tiktok' => 'tiktok',
        't.co' => 'x', 'twitter' => 'x', 'booking.com' => 'booking', 'tripadvisor' => 'tripadvisor', 'wa.me' => 'whatsapp', 'whatsapp' => 'whatsapp'];
    $auto = '';
    foreach ($map as $needle => $name) {
        if ($ref !== '' && str_contains($ref, $needle)) {
            $auto = $name;
            break;
        }
    }
    // landing pages for booking platforms count as that platform when no utm tag is given
    if ($utm === '' && preg_match('#^/[a-z]{2}/via/([a-z]+)$#', (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), $vm) && isset(PLATFORMS[$vm[1]])) {
        $utm = $vm[1];
    }
    $_SESSION['src'] = [
        'utm_source' => $utm, 'utm_medium' => $clean($_GET['utm_medium'] ?? ''), 'utm_campaign' => $clean($_GET['utm_campaign'] ?? ''),
        'referrer' => $ref, 'label' => $utm !== '' ? strtolower($utm) : ($auto !== '' ? $auto : ($ref !== '' ? 'other-site' : 'direct')),
    ];
    record_event('session', $_SESSION['src']['label']);
}

function api_event(): void
{
    header('Cache-Control: no-store');
    $host = strtolower((string)parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
    $own = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '' || $host !== $own || !throttle('event', 60, 60)) {
        http_response_code(204);
        return;
    }
    $type = (string)($_POST['type'] ?? '');
    if (in_array($type, ['whatsapp', 'call', 'email', 'link', 'directions'], true)) {
        record_event($type, preg_replace('/[^a-z0-9.\-]/i', '', (string)($_POST['label'] ?? '')));
    }
    http_response_code(204);
}

function active_rooms(): array
{
    return db()->query('SELECT * FROM rooms WHERE active = 1 ORDER BY sort, id')->fetchAll();
}

function room_by_slug(string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM rooms WHERE slug = ? AND active = 1');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

/** Link-preview bots (WhatsApp, Facebook, Telegram, X...) should get the page itself, not a redirect. */
function is_link_bot(): bool
{
    return (bool)preg_match('/WhatsApp|facebookexternalhit|Facebot|Twitterbot|TelegramBot|LinkedInBot|Slackbot|Discordbot|SkypeUriPreview|Pinterest|Googlebot|bingbot/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function lang_url(string $lang, string $path = ''): string
{
    return '/' . $lang . '/' . ltrim($path, '/');
}

/** Facebook / Instagram / Airbnb links shown as icons in the header (only the ones that have a URL). */
function header_socials(): array
{
    $out = [];
    $st = db()->query("SELECT lkey, url FROM links WHERE url <> '' AND lkey IN ('facebook','instagram','airbnb','agoda','trivago') ORDER BY CASE lkey WHEN 'facebook' THEN 1 WHEN 'instagram' THEN 2 WHEN 'airbnb' THEN 3 WHEN 'agoda' THEN 4 ELSE 5 END");
    foreach ($st as $r) {
        $out[$r['lkey']] = $r['url'];
    }
    return $out;
}

function social_icon(string $key): string
{
    $paths = [
        'facebook' => '<path d="M13.5 22v-8.2h2.8l.5-3.3h-3.3V8.4c0-.9.4-1.7 1.8-1.7h1.6V3.8c-.3 0-1.3-.2-2.4-.2-2.5 0-4.1 1.5-4.1 4.2v2.7H7.6v3.3h2.8V22h3.1z"/>',
        'instagram' => '<path d="M7.5 2h9A5.5 5.5 0 0 1 22 7.5v9a5.5 5.5 0 0 1-5.5 5.5h-9A5.5 5.5 0 0 1 2 16.5v-9A5.5 5.5 0 0 1 7.5 2zm0 2A3.5 3.5 0 0 0 4 7.5v9A3.5 3.5 0 0 0 7.5 20h9a3.5 3.5 0 0 0 3.5-3.5v-9A3.5 3.5 0 0 0 16.5 4h-9zM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6zm5.3-3.3a1.2 1.2 0 1 1 0 2.4 1.2 1.2 0 0 1 0-2.4z"/>',
        'airbnb' => '<path d="M12 2c-1.3 0-2.3.8-3 2.1L4.1 14c-.5 1.1-.7 1.9-.7 2.6 0 2.1 1.6 3.7 3.7 3.7 1.7 0 3.2-1.1 4.9-3 1.7 1.9 3.2 3 4.9 3 2.1 0 3.7-1.6 3.7-3.7 0-.7-.2-1.5-.7-2.6L15 4.1C14.3 2.8 13.3 2 12 2zm0 2c.5 0 .9.4 1.3 1.1l4.5 9.4c.4.8.5 1.3.5 1.9 0 1-.8 1.8-1.8 1.8-.9 0-2-.9-3.4-2.7.9-1.2 1.5-2.4 1.5-3.5 0-1.6-1.2-2.8-2.6-2.8s-2.6 1.2-2.6 2.8c0 1.1.6 2.3 1.5 3.5-1.4 1.8-2.5 2.7-3.4 2.7-1 0-1.8-.8-1.8-1.8 0-.6.1-1.1.5-1.9l4.5-9.4c.4-.7.8-1.1 1.3-1.1zm0 7.4c.4 0 .6.4.6.9 0 .6-.3 1.4-.6 2-.3-.6-.6-1.4-.6-2 0-.5.2-.9.6-.9z"/>',
        'agoda' => '<text x="12" y="17.5" text-anchor="middle" font-size="15" font-weight="700" font-family="Arial,sans-serif">a</text>',
        'trivago' => '<text x="12" y="17.5" text-anchor="middle" font-size="15" font-weight="700" font-family="Arial,sans-serif">t</text>',
    ];
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">' . ($paths[$key] ?? '') . '</svg>';
}

// ---------- shared layout ----------
/** $path is the page path without language, e.g. '' , 'faq', 'rooms/single' (used for hreflang). */
function page_open(string $lang, string $title, string $desc, string $path, ?array $ld = null, ?string $ogImage = null): void
{
    $u = ui($lang);
    $name = tr_json(setting('name_json'), $lang);
    $langs = enabled_langs();
    $base = base_url();
    $has_gallery = (int)db()->query('SELECT COUNT(*) FROM gallery')->fetchColumn() > 0;
    $has_amen = (int)db()->query('SELECT COUNT(*) FROM amenities WHERE confirmed = 1')->fetchColumn() > 0;
    $home = lang_url($lang);
    $promo = trim(tr_json(setting('promo_json'), $lang));
    security_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="<?= e($lang) ?>" dir="<?= LANGS[$lang]['rtl'] ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= e($base . lang_url($lang, $path)) ?>">
<?php foreach ($langs as $l): ?>
<link rel="alternate" hreflang="<?= e($l) ?>" href="<?= e($base . lang_url($l, $path)) ?>">
<?php endforeach; ?>
<link rel="alternate" hreflang="x-default" href="<?= e($base . lang_url('en', $path)) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="<?= e(LANGS[$lang]['locale']) ?>">
<meta property="og:site_name" content="<?= e($name) ?>">
<meta property="og:url" content="<?= e($base . lang_url($lang, $path)) ?>">
<meta name="twitter:card" content="<?= $ogImage ? 'summary_large_image' : 'summary' ?>">
<?php if ($ogImage):
    $dim = preg_match('#^/uploads/[a-f0-9]{20}\.(jpg|png|webp)$#', $ogImage) ? @getimagesize(UPLOAD_DIR . '/' . basename($ogImage)) : (str_starts_with($ogImage, '/assets/brand/') ? @getimagesize(ROOT . '/public' . $ogImage) : false); ?>
<meta property="og:image" content="<?= e($base . $ogImage) ?>">
<meta property="og:image:alt" content="<?= e($name) ?>">
<?php if ($dim): ?><meta property="og:image:width" content="<?= (int)$dim[0] ?>">
<meta property="og:image:height" content="<?= (int)$dim[1] ?>">
<?php endif; ?><meta name="twitter:image" content="<?= e($base . $ogImage) ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Cairo:wght@400;600;800&family=Cormorant+Garamond:wght@500;700&family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/favicon-32.png">
<link rel="apple-touch-icon" href="/assets/brand/favicon-192.png">
<meta name="theme-color" content="#24150c">
<link rel="stylesheet" href="/assets/site.css?v=5">
<?php if ($ld): ?><script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script><?php endif; ?>
</head>
<body>
<?php if ($promo !== ''): $pl = setting('promo_link'); ?>
<div class="promo"><?= $pl ? '<a href="' . e($pl) . '">' : '' ?><?= e($promo) ?><?= $pl ? '</a>' : '' ?></div>
<?php endif; ?>
<header class="top">
 <div class="top-in">
  <a class="brand" href="<?= e($home) ?>"><?= e($name) ?></a>
  <nav>
    <a href="<?= e($home) ?>#rooms"><?= e($u['nav_rooms']) ?></a>
    <?php if ($has_amen): ?><a href="<?= e($home) ?>#amenities"><?= e($u['nav_amenities']) ?></a><?php endif; ?>
    <?php if ($has_gallery): ?><a href="<?= e($home) ?>#gallery"><?= e($u['nav_gallery']) ?></a><?php endif; ?>
    <a href="<?= e($home) ?>#location"><?= e($u['nav_location']) ?></a>
    <a href="<?= e(lang_url($lang, 'faq')) ?>"><?= e($u['nav_faq']) ?></a>
    <a href="<?= e(lang_url($lang, 'contact')) ?>"><?= e($u['nav_contact']) ?></a>
    <a href="<?= e(lang_url($lang, 'book-direct')) ?>"><?= e($u['nav_direct']) ?></a>
    <a href="<?= e($home) ?>#book" class="cta-sm"><?= e($u['nav_book']) ?></a>
  </nav>
  <div class="tools">
    <?php foreach (header_socials() as $sk => $su): ?>
      <a class="ico" target="_blank" rel="noopener nofollow me" href="<?= e($su) ?>" aria-label="<?= e(ucfirst($sk)) ?>" title="<?= e(ucfirst($sk)) ?>"><?= social_icon($sk) ?></a>
    <?php endforeach; ?>
    <details class="langs">
      <summary><?= e(LANGS[$lang]['name']) ?></summary>
      <ul>
        <?php foreach ($langs as $l): ?>
          <li><a href="<?= e(lang_url($l, $path)) ?>" hreflang="<?= e($l) ?>"<?= $l === $lang ? ' aria-current="true"' : '' ?>><?= e(LANGS[$l]['name']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </details>
  </div>
 </div>
 <div class="band"></div>
</header>
<?php
}

function page_close(string $lang): void
{
    $u = ui($lang);
    $phone = setting('phone');
    $wa = preg_replace('/\D/', '', setting('whatsapp'));
    ?>
<footer>
  <p class="fn"><?= e(tr_json(setting('name_json'), $lang)) ?></p>
  <p class="small"><?= e(tr_json(setting('address_json'), $lang)) ?></p>
  <p class="small"><?php if ($phone): ?><a href="tel:<?= e($phone) ?>" dir="ltr"><?= e($phone) ?></a><?php endif; ?>
  <?php if (setting('email')): ?> · <a href="mailto:<?= e(setting('email')) ?>"><?= e(setting('email')) ?></a><?php endif; ?></p>
  <p class="small"><?= e($u['disclaimer']) ?></p>
</footer>
<?php if ($wa): ?><a class="wa-float" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= e(rawurlencode($u['wa_hello'])) ?>" aria-label="WhatsApp">WhatsApp</a><?php endif; ?>
<script src="/assets/site.js" defer></script>
</body>
</html>
<?php
}

function booking_form(string $lang, array $rooms, string $selected, string $ret): void
{
    $u = ui($lang);
    $wa = preg_replace('/\D/', '', setting('whatsapp'));
    $sent = isset($_GET['sent']);
    $err = (string)($_GET['err'] ?? '');
    ?>
  <section id="book" class="wrap">
    <h2><?= e($u['h_book']) ?></h2>
    <?php if ($sent): ?>
      <div class="ok-box"><strong><?= e($u['thanks']) ?></strong><br><?= e($u['thanks_sub']) ?>
        <?php if ($wa): ?><br><a class="btn" style="margin-top:12px" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= e(rawurlencode($u['wa_hello'])) ?>"><?= e($u['send_wa']) ?></a><?php endif; ?>
      </div>
    <?php else: ?>
      <p class="sub"><?= e($u['book_sub']) ?></p>
      <?php if (in_array($err, ERR_KEYS, true)): ?><p class="err-box"><?= e($u[$err]) ?></p><?php endif; ?>
      <form method="post" action="/request" class="form" id="bookform" data-quote="/api/quote" data-nights="<?= e($u['nights']) ?>" data-total="<?= e($u['est_total']) ?>" data-na="<?= e($u['not_available']) ?>" data-cur="<?= e($u['currency']) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="lang" value="<?= e($lang) ?>">
        <input type="hidden" name="ret" value="<?= e($ret) ?>">
        <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        <label><span><?= e($u['f_name']) ?></span><input name="name" required maxlength="80" autocomplete="name"></label>
        <label><span><?= e($u['f_phone']) ?></span><input name="phone" required maxlength="30" inputmode="tel" autocomplete="tel"></label>
        <label><span><?= e($u['f_room']) ?></span>
          <select name="room"><?php foreach ($rooms as $r): ?><option value="<?= e($r['slug']) ?>"<?= $r['slug'] === $selected ? ' selected' : '' ?>><?= e(tr_json($r['name_json'], $lang)) ?></option><?php endforeach; ?></select></label>
        <label><span><?= e($u['f_in']) ?></span><input type="date" name="in" required min="<?= date('Y-m-d') ?>"></label>
        <label><span><?= e($u['f_out']) ?></span><input type="date" name="out" required min="<?= date('Y-m-d') ?>"></label>
        <label><span><?= e($u['f_guests']) ?></span><input type="number" name="guests" min="1" max="12" value="1" required></label>
        <div class="quote full" id="quote" hidden></div>
        <label class="full"><span><?= e($u['f_msg']) ?></span><textarea name="message" rows="3" maxlength="1000"></textarea></label>
        <button class="btn" type="submit"><?= e($u['send_req']) ?></button>
        <p class="note"><?= e($u['note']) ?></p>
      </form>
    <?php endif; ?>
  </section>
<?php
}

function nearby_list(string $lang): string
{
    $u = ui($lang);
    $out = '';
    $modes = ['walk' => 'mode_walk', 'metro' => 'mode_metro', 'taxi' => 'mode_taxi'];
    foreach (items('attraction') as $it) {
        $d = $it['d'];
        $mode = $modes[$d['mode'] ?? 'walk'] ?? 'mode_walk';
        $note = tr_json(json_encode($d['note'] ?? []), $lang);
        $out .= '<li><b>' . e(tr_json(json_encode($d['name'] ?? []), $lang)) . '</b> – ' . (int)($d['min'] ?? 0) . ' ' . e($u[$mode]) . ($note !== '' ? '<br><span class="muted">' . e($note) . '</span>' : '') . '</li>';
    }
    return $out;
}

function map_iframe(string $lang): string
{
    return '<iframe title="map" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://maps.google.com/maps?q='
        . e(setting('lat') . ',' . setting('lng')) . '&z=16&output=embed&hl=' . e($lang) . '"></iframe>';
}

function hotel_ld(string $lang): array
{
    $rooms = active_rooms();
    $prices = array_column($rooms, 'price');
    $links = db()->query("SELECT url FROM links WHERE url <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $gal = db()->query('SELECT path FROM gallery ORDER BY sort, id LIMIT 1')->fetchColumn();
    $ld = [
        '@context' => 'https://schema.org', '@type' => 'Hostel', 'name' => tr_json(setting('name_json'), $lang),
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => tr_json(setting('address_json'), $lang), 'addressLocality' => 'Cairo', 'addressCountry' => 'EG'],
        'geo' => ['@type' => 'GeoCoordinates', 'latitude' => (float)setting('lat'), 'longitude' => (float)setting('lng')],
        'url' => base_url() . lang_url($lang),
    ];
    if ($prices) {
        $ld['priceRange'] = min($prices) . '-' . max($prices) . ' EGP';
    }
    if (setting('phone') !== '') {
        $ld['telephone'] = setting('phone');
    }
    if ($links) {
        $ld['sameAs'] = $links;
    }
    if ($gal) {
        $ld['image'] = base_url() . $gal;
    }
    return $ld;
}

// ---------- pages ----------
function render_home(string $lang): void
{
    $u = ui($lang);
    $name = tr_json(setting('name_json'), $lang);
    $tagline = tr_json(setting('tagline_json'), $lang);
    $rooms = active_rooms();
    $amen = db()->query('SELECT * FROM amenities WHERE confirmed = 1 ORDER BY sort, id')->fetchAll();
    $gallery = db()->query('SELECT * FROM gallery ORDER BY sort, id')->fetchAll();
    $links = db()->query("SELECT * FROM links WHERE url <> '' ORDER BY sort")->fetchAll();
    $tests = items('testimonial');
    $hero = setting('hero_image');
    $seoT = tr_json(setting('seo_title_json'), $lang);
    $seoD = tr_json(setting('seo_desc_json'), $lang);
    $min = $rooms ? min(array_column($rooms, 'price')) : 0;

    page_open($lang, $seoT !== '' ? $seoT : $name . ' – ' . $u['eyebrow'], $seoD !== '' ? $seoD : $tagline, '', hotel_ld($lang), '/assets/brand/social-card-1200x630.jpg');
    ?>
<main id="top">
  <section class="hero lattice"<?= $hero ? ' style="background-image:linear-gradient(180deg,rgba(36,21,12,.35) 0%,rgba(36,21,12,.62) 55%,rgba(36,21,12,.92) 100%),url(' . e($hero) . ');background-size:cover;background-position:center"' : '' ?>>
    <div class="hero-in">
      <p class="eyebrow"><?= e($u['eyebrow']) ?></p>
      <h1><?= e($name) ?></h1>
      <p class="lead"><?= e($tagline) ?></p>
      <div class="hero-cta">
        <a class="btn" href="#book"><?= e($u['cta_book']) ?></a>
        <a class="btn ghost" href="#rooms"><?= e($u['cta_rooms']) ?></a>
      </div>
      <?php if ($rooms): ?><p class="from"><?= e($u['from']) ?> <?= e(money((int)$min, $lang)) ?></p><?php endif; ?>
    </div>
  </section>

  <section id="rooms" class="wrap">
    <h2><?= e($u['h_rooms']) ?></h2>
    <p class="sub"><?= e($u['rooms_sub']) ?></p>
    <div class="grid">
      <?php foreach ($rooms as $r): $photos = json_decode($r['photos_json'], true) ?: []; $rn = tr_json($r['name_json'], $lang); ?>
        <a class="card" href="<?= e(lang_url($lang, 'rooms/' . $r['slug'])) ?>">
          <div class="ph"><?php if ($photos): ?><img loading="lazy" src="<?= e(thumb($photos[0])) ?>" alt="<?= e($rn) ?>"><?php else: ?><?= e($u['photo_soon']) ?><?php endif; ?></div>
          <div class="card-b">
            <h3><?= e($rn) ?></h3>
            <p><?= e(tr_json($r['desc_json'], $lang)) ?></p>
            <div class="price"><?= e(money((int)$r['price'], $lang)) ?> <small><?= e($u[$r['unit'] === 'bed' ? 'per_bed' : 'per_night']) ?></small><?php if ($ap = approx((int)$r['price'], $lang)): ?> <small class="ap"><?= e($ap) ?></small><?php endif; ?></div>
            <span class="more"><?= e($u['view_room']) ?> →</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($amen): ?>
  <section id="amenities" class="wrap dark lattice">
    <h2><?= e($u['h_amenities']) ?></h2>
    <ul class="chips">
      <?php foreach ($amen as $a): ?><li><span class="i"><?= e($a['icon']) ?></span> <?= e(tr_json($a['label_json'], $lang)) ?></li><?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if ($gallery): ?>
  <section id="gallery" class="wrap">
    <h2><?= e($u['h_gallery']) ?></h2>
    <div class="gal">
      <?php foreach ($gallery as $g): ?><a href="<?= e($g['path']) ?>"><img loading="lazy" src="<?= e(thumb($g['path'])) ?>" alt="<?= e(tr_json($g['caption_json'], $lang) ?: $name) ?>"></a><?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($tests): ?>
  <section class="wrap alt">
    <div class="quotes">
      <?php foreach ($tests as $t): $d = $t['d']; ?>
        <blockquote><p>“<?= e(tr_json(json_encode($d['text'] ?? []), $lang)) ?>”</p><footer>— <?= e($d['author'] ?? '') ?><?= !empty($d['country']) ? ', ' . e($d['country']) : '' ?><?= !empty($d['source']) ? ' · ' . e($d['source']) : '' ?></footer></blockquote>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section id="location" class="wrap">
    <h2><?= e($u['h_location']) ?></h2>
    <div class="loc">
      <div>
        <p class="addr"><?= e(tr_json(setting('address_json'), $lang)) ?></p>
        <ul class="near"><?= nearby_list($lang) ?></ul>
        <?php if (setting('maps_url') !== ''): ?><a class="btn dir" target="_blank" rel="noopener" href="<?= e(setting('maps_url')) ?>"><?= e($u['directions']) ?></a><?php endif; ?>
      </div>
      <?= map_iframe($lang) ?>
    </div>
  </section>

  <?php if ($links): ?>
  <section id="find" class="wrap alt">
    <h2><?= e($u['h_find']) ?></h2>
    <p class="sub"><?= e($u['find_sub']) ?></p>
    <div class="links">
      <?php foreach ($links as $l): ?><a class="lk" target="_blank" rel="noopener nofollow" href="<?= e($l['url']) ?>"><?= e($l['label']) ?></a><?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php booking_form($lang, $rooms, '', ''); ?>
</main>
<?php
    page_close($lang);
}

function render_room(string $lang, array $room): void
{
    $u = ui($lang);
    $name = tr_json(setting('name_json'), $lang);
    $rn = tr_json($room['name_json'], $lang);
    $desc = tr_json($room['desc_json'], $lang);
    $photos = json_decode($room['photos_json'], true) ?: [];
    $amen = db()->query('SELECT * FROM amenities WHERE confirmed = 1 ORDER BY sort, id')->fetchAll();
    $others = array_filter(active_rooms(), fn($r) => $r['slug'] !== $room['slug']);
    $ld = ['@context' => 'https://schema.org', '@type' => 'HotelRoom', 'name' => $rn, 'description' => $desc,
        'occupancy' => ['@type' => 'QuantitativeValue', 'value' => (int)$room['guests']],
        'offers' => ['@type' => 'Offer', 'price' => (int)$room['price'], 'priceCurrency' => 'EGP'],
        'isPartOf' => ['@type' => 'Hostel', 'name' => $name, 'url' => base_url() . lang_url($lang)]];
    if ($photos) {
        $ld['image'] = array_map(fn($p) => base_url() . $p, $photos);
    }
    page_open($lang, $rn . ' – ' . $name, $desc, 'rooms/' . $room['slug'], $ld, $photos[0] ?? null);
    ?>
<main>
  <section class="wrap">
    <nav class="crumbs"><a href="<?= e(lang_url($lang)) ?>"><?= e($u['home']) ?></a> › <a href="<?= e(lang_url($lang)) ?>#rooms"><?= e($u['nav_rooms']) ?></a> › <?= e($rn) ?></nav>
    <h1 class="pt"><?= e($rn) ?></h1>
    <p class="price big"><?= e(money((int)$room['price'], $lang)) ?> <small><?= e($u[$room['unit'] === 'bed' ? 'per_bed' : 'per_night']) ?></small><?php if ($ap = approx((int)$room['price'], $lang)): ?> <small class="ap"><?= e($ap) ?></small><?php endif; ?></p>
    <p class="lead2"><?= e($desc) ?></p>
    <p class="muted"><?= e($u['sleeps']) ?>: <?= (int)$room['guests'] ?></p>
    <?php if ($photos): ?>
      <div class="gal roomgal"><?php foreach ($photos as $p): ?><a href="<?= e($p) ?>"><img loading="lazy" src="<?= e(thumb($p)) ?>" alt="<?= e($rn) ?>"></a><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($amen): ?>
      <h2><?= e($u['room_inc']) ?></h2>
      <ul class="chips light"><?php foreach ($amen as $a): ?><li><span class="i"><?= e($a['icon']) ?></span> <?= e(tr_json($a['label_json'], $lang)) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($others): ?>
      <h2><?= e($u['back_rooms']) ?></h2>
      <div class="links"><?php foreach ($others as $o): ?><a class="lk" href="<?= e(lang_url($lang, 'rooms/' . $o['slug'])) ?>"><?= e(tr_json($o['name_json'], $lang)) ?> · <?= e(money((int)$o['price'], $lang)) ?></a><?php endforeach; ?></div>
    <?php endif; ?>
  </section>
  <?php booking_form($lang, active_rooms(), $room['slug'], 'rooms/' . $room['slug']); ?>
</main>
<?php
    page_close($lang);
}

function render_faq(string $lang): void
{
    $u = ui($lang);
    $name = tr_json(setting('name_json'), $lang);
    $faq = items('faq');
    $pol = items('policy');
    $ld = null;
    if ($faq) {
        $ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => [
            '@type' => 'Question', 'name' => tr_json(json_encode($f['d']['q'] ?? []), $lang),
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => tr_json(json_encode($f['d']['a'] ?? []), $lang)]], $faq)];
    }
    page_open($lang, $u['h_faq'] . ' – ' . $name, $u['h_policies'] . ' · ' . $name, 'faq', $ld, db()->query('SELECT path FROM gallery ORDER BY sort, id LIMIT 1')->fetchColumn() ?: null);
    ?>
<main>
  <section class="wrap">
    <h1 class="pt"><?= e($u['h_faq']) ?></h1>
    <?php if (!$faq): ?><p class="muted"><?= e($u['faq_empty']) ?></p><?php endif; ?>
    <div class="faq">
      <?php foreach ($faq as $f): ?>
        <details><summary><?= e(tr_json(json_encode($f['d']['q'] ?? []), $lang)) ?></summary><p><?= nl2br(e(tr_json(json_encode($f['d']['a'] ?? []), $lang))) ?></p></details>
      <?php endforeach; ?>
    </div>
    <?php if ($pol): ?>
      <h2><?= e($u['h_policies']) ?></h2>
      <div class="policies">
        <?php foreach ($pol as $p): ?>
          <div class="pol"><h3><?= e(tr_json(json_encode($p['d']['title'] ?? []), $lang)) ?></h3><p><?= nl2br(e(tr_json(json_encode($p['d']['text'] ?? []), $lang))) ?></p></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
  <?php booking_form($lang, active_rooms(), '', 'faq'); ?>
</main>
<?php
    page_close($lang);
}

function render_contact(string $lang): void
{
    $u = ui($lang);
    $name = tr_json(setting('name_json'), $lang);
    $phone = setting('phone');
    $wa = preg_replace('/\D/', '', setting('whatsapp'));
    page_open($lang, $u['h_contact'] . ' – ' . $name, tr_json(setting('address_json'), $lang), 'contact', hotel_ld($lang), db()->query('SELECT path FROM gallery ORDER BY sort, id LIMIT 1 OFFSET 2')->fetchColumn() ?: null);
    ?>
<main>
  <section class="wrap">
    <h1 class="pt"><?= e($u['h_contact']) ?></h1>
    <div class="loc">
      <div class="contact">
        <p><b><?= e($u['address_lbl']) ?></b><br><?= e(tr_json(setting('address_json'), $lang)) ?></p>
        <?php if ($phone): ?><p><b><?= e($u['phone_lbl']) ?></b><br><a href="tel:<?= e($phone) ?>" dir="ltr"><?= e($phone) ?></a></p><?php endif; ?>
        <?php if (setting('email')): ?><p><b>Email</b><br><a href="mailto:<?= e(setting('email')) ?>" dir="ltr"><?= e(setting('email')) ?></a></p><?php endif; ?>
        <?php if (setting('checkin') || setting('checkout')): ?><p><b><?= e($u['hours_lbl']) ?></b><br><?= e($u['checkin_time']) ?>: <?= e(setting('checkin')) ?> · <?= e($u['checkout_time']) ?>: <?= e(setting('checkout')) ?></p><?php endif; ?>
        <ul class="near"><?= nearby_list($lang) ?></ul>
        <div class="hero-cta">
          <?php if ($phone): ?><a class="btn" href="tel:<?= e($phone) ?>"><?= e($u['call_us']) ?></a><?php endif; ?>
          <?php if ($wa): ?><a class="btn gold" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= e(rawurlencode($u['wa_hello'])) ?>"><?= e($u['open_wa']) ?></a><?php endif; ?>
          <?php if (setting('maps_url') !== ''): ?><a class="btn ghost dk dir" target="_blank" rel="noopener" href="<?= e(setting('maps_url')) ?>"><?= e($u['directions']) ?></a><?php endif; ?>
        </div>
      </div>
      <?= map_iframe($lang) ?>
    </div>
  </section>
  <?= howto_block($lang) ?>
  <?php booking_form($lang, active_rooms(), '', 'contact'); ?>
</main>
<?php
    page_close($lang);
}

/** Landing pages for guests who find the hotel on a booking platform: /{lang}/book-direct and /{lang}/via/{platform}. */
function render_ota(string $lang, ?string $platform): void
{
    $u = ui($lang);
    $name = tr_json(setting('name_json'), $lang);
    $label = $platform !== null ? PLATFORMS[$platform] : '';
    $h1 = $platform !== null ? str_replace('{p}', $label, $u['ota_h_via']) : $u['ota_h'];
    $desc = $platform !== null ? str_replace('{p}', $label, $u['ota_sub_via']) : $u['ota_sub'];
    $path = $platform !== null ? 'via/' . $platform : 'book-direct';
    $rooms = active_rooms();
    $phone = setting('phone');
    $wa = preg_replace('/\D/', '', setting('whatsapp'));
    $gallery = db()->query('SELECT path FROM gallery ORDER BY sort, id LIMIT 1')->fetchColumn() ?: null;
    page_open($lang, $h1 . ' – ' . $name, $desc, $path, hotel_ld($lang), $gallery);
    ?>
<main>
  <section class="wrap">
    <h1 class="pt"><?= e($h1) ?></h1>
    <p class="sub"><?= e($desc) ?></p>
    <ul class="near">
      <?php foreach (['ota_b1', 'ota_b2', 'ota_b3', 'ota_b4'] as $k): ?><li><?= e($u[$k]) ?></li><?php endforeach; ?>
    </ul>
    <div class="hero-cta">
      <a class="btn" href="#book"><?= e($u['cta_book']) ?></a>
      <?php if ($wa): ?><a class="btn gold" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= e(rawurlencode($u['wa_hello'])) ?>"><?= e($u['open_wa']) ?></a><?php endif; ?>
      <?php if ($phone): ?><a class="btn ghost dk" href="tel:<?= e($phone) ?>"><?= e($u['call_us']) ?></a><?php endif; ?>
    </div>
    <?php if ($rooms): ?>
      <h2><?= e($u['h_rooms']) ?></h2>
      <div class="rooms">
        <?php foreach ($rooms as $r): ?>
          <a class="btn ghost dk" href="<?= e(lang_url($lang, 'rooms/' . $r['slug'])) ?>"><?= e(tr_json($r['name_json'], $lang)) ?> · <?= e($u['from_night']) ?> <?= e(money((int)$r['price'], $lang)) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="muted"><?= e($u['ota_note']) ?></p>
  </section>
  <?php booking_form($lang, $rooms, '', $path); ?>
</main>
<?php
    page_close($lang);
}

function render_404(string $lang): void
{
    http_response_code(404);
    $u = ui($lang);
    page_open($lang, $u['page_nf'], $u['page_nf'], '');
    echo '<main><section class="wrap"><h1 class="pt">404</h1><p>' . e($u['page_nf']) . '</p><a class="btn" href="' . e(lang_url($lang)) . '">' . e($u['home']) . '</a></section></main>';
    page_close($lang);
}

// ---------- API & form ----------
function api_quote(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!throttle('quote', 90, 60)) {
        http_response_code(429);
        echo json_encode(['ok' => false]);
        return;
    }
    $room = room_by_slug((string)($_GET['room'] ?? ''));
    $in = DateTime::createFromFormat('Y-m-d', (string)($_GET['in'] ?? ''));
    $out = DateTime::createFromFormat('Y-m-d', (string)($_GET['out'] ?? ''));
    $guests = max(1, min(12, (int)($_GET['guests'] ?? 1)));
    if (!$room || !$in || !$out || $out <= $in || $in < new DateTime('today') || $in->diff($out)->days > 60) {
        echo json_encode(['ok' => false]);
        return;
    }
    $i = $in->format('Y-m-d');
    $o = $out->format('Y-m-d');
    echo json_encode(['ok' => true, 'nights' => $in->diff($out)->days, 'total' => quote_total($room, $i, $o, $guests), 'available' => room_available($room, $i, $o)]);
}

function handle_request_post(): void
{
    $lang = (string)($_POST['lang'] ?? 'en');
    if (!in_array($lang, enabled_langs(), true)) {
        $lang = 'en';
    }
    $ret = (string)($_POST['ret'] ?? '');
    if ($ret !== '' && !preg_match('#^(faq|contact|rooms/[a-z0-9\-]+)$#', $ret)) {
        $ret = '';
    }
    $fail = fn(string $key): never => redirect(lang_url($lang, $ret) . '?err=' . $key . '#book');
    if (!csrf_ok()) {
        $fail('err_generic');
    }
    if (!empty($_POST['website'])) { // honeypot: pretend success
        redirect(lang_url($lang, $ret) . '?sent=1#book');
    }
    if (!throttle('request', 5, 3600)) {
        $fail('err_rate');
    }
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $in = (string)($_POST['in'] ?? '');
    $out = (string)($_POST['out'] ?? '');
    $guests = max(1, min(12, (int)($_POST['guests'] ?? 1)));
    $msg = trim((string)($_POST['message'] ?? ''));
    if ($name === '' || mb_strlen($name) > 80 || !preg_match('/^[0-9+\-\s()]{6,30}$/', $phone)) {
        $fail('err_generic');
    }
    $d1 = DateTime::createFromFormat('Y-m-d', $in);
    $d2 = DateTime::createFromFormat('Y-m-d', $out);
    if (!$d1 || !$d2 || $d1 < new DateTime('today') || $d2 <= $d1 || $d1->diff($d2)->days > 60) {
        $fail('err_dates');
    }
    $room = room_by_slug((string)($_POST['room'] ?? ''));
    if (!$room) {
        $fail('err_generic');
    }
    ical_sync_if_stale(600); // make sure OTA bookings are fresh before promising availability
    if (!room_available($room, $in, $out)) {
        $fail('not_available');
    }
    $total = quote_total($room, $in, $out, $guests);
    $src = $_SESSION['src'] ?? [];
    db()->prepare('INSERT INTO requests(created_at, name, phone, room_slug, checkin, checkout, guests, lang, message, ip, est_total, utm_source, utm_medium, utm_campaign, referrer) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([gmdate('Y-m-d H:i:s'), $name, $phone, $room['slug'], $in, $out, $guests, $lang, mb_substr($msg, 0, 1000), client_ip(), $total,
            $src['utm_source'] ?? '', $src['utm_medium'] ?? '', $src['utm_campaign'] ?? '', $src['referrer'] ?? '']);
    record_event('request', (string)($src['label'] ?? 'direct'));

    $to = setting('notify_email');
    if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $clean = fn(string $s): string => preg_replace('/[\r\n]+/', ' ', $s);
        @mail($to, '=?UTF-8?B?' . base64_encode('New booking request: ' . $clean($name)) . '?=',
            "Name: " . $clean($name) . "\nPhone: " . $clean($phone) . "\nRoom: {$room['slug']}\nDates: $in -> $out\nGuests: $guests\nEstimated: $total EGP\nLang: $lang\n\n" . $msg,
            "Content-Type: text/plain; charset=UTF-8\r\nFrom: no-reply@" . preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')));
    }
    redirect(lang_url($lang, $ret) . '?sent=1#book');
}

function sitemap(): void
{
    $base = base_url();
    $paths = ['', 'faq', 'contact', 'book-direct'];
    foreach (active_rooms() as $r) {
        $paths[] = 'rooms/' . $r['slug'];
    }
    foreach (array_keys(PLATFORMS) as $pl) {
        if ($pl !== 'other') {
            $paths[] = 'via/' . $pl;
        }
    }
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';
    foreach ($paths as $p) {
        foreach (enabled_langs() as $l) {
            echo '<url><loc>' . e($base . lang_url($l, $p)) . '</loc>';
            foreach (enabled_langs() as $a) {
                echo '<xhtml:link rel="alternate" hreflang="' . e($a) . '" href="' . e($base . lang_url($a, $p)) . '"/>';
            }
            echo '</url>';
        }
    }
    echo '</urlset>';
}
