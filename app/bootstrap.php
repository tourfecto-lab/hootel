<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('DATA_DIR', ROOT . '/data');
define('UPLOAD_DIR', ROOT . '/public/uploads');

/** Languages supported. rtl = right-to-left. Add a file in app/lang/<code>.php to add a language. */
const LANGS = [
    'ar' => ['name' => 'العربية',  'rtl' => true,  'locale' => 'ar_EG'],
    'en' => ['name' => 'English',  'rtl' => false, 'locale' => 'en_US'],
    'de' => ['name' => 'Deutsch',  'rtl' => false, 'locale' => 'de_DE'],
    'fr' => ['name' => 'Français', 'rtl' => false, 'locale' => 'fr_FR'],
    'it' => ['name' => 'Italiano', 'rtl' => false, 'locale' => 'it_IT'],
    'es' => ['name' => 'Español',  'rtl' => false, 'locale' => 'es_ES'],
    'ru' => ['name' => 'Русский',  'rtl' => false, 'locale' => 'ru_RU'],
    'zh' => ['name' => '中文',      'rtl' => false, 'locale' => 'zh_CN'],
];

if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https, 'path' => '/']);
    session_name('kp_sess');
    session_start();
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0750, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/site.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $p): void
{
    $p->exec("
    CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT '');
    CREATE TABLE IF NOT EXISTS rooms (
        id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE NOT NULL,
        price INTEGER NOT NULL DEFAULT 0, unit TEXT NOT NULL DEFAULT 'room',
        guests INTEGER NOT NULL DEFAULT 1, qty INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
        name_json TEXT NOT NULL DEFAULT '{}', desc_json TEXT NOT NULL DEFAULT '{}',
        photos_json TEXT NOT NULL DEFAULT '[]'
    );
    CREATE TABLE IF NOT EXISTS amenities (
        id INTEGER PRIMARY KEY AUTOINCREMENT, akey TEXT UNIQUE NOT NULL, icon TEXT NOT NULL DEFAULT '',
        confirmed INTEGER NOT NULL DEFAULT 0, sort INTEGER NOT NULL DEFAULT 0, label_json TEXT NOT NULL DEFAULT '{}'
    );
    CREATE TABLE IF NOT EXISTS links (lkey TEXT PRIMARY KEY, label TEXT NOT NULL, url TEXT NOT NULL DEFAULT '', sort INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS gallery (id INTEGER PRIMARY KEY AUTOINCREMENT, path TEXT NOT NULL, caption_json TEXT NOT NULL DEFAULT '{}', sort INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE IF NOT EXISTS requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL, name TEXT NOT NULL, phone TEXT NOT NULL DEFAULT '',
        room_slug TEXT NOT NULL DEFAULT '', checkin TEXT NOT NULL, checkout TEXT NOT NULL, guests INTEGER NOT NULL DEFAULT 1,
        lang TEXT NOT NULL DEFAULT 'en', message TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'new',
        note TEXT NOT NULL DEFAULT '', ip TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS admins (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, pass_hash TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS throttle (bucket TEXT NOT NULL, ip TEXT NOT NULL, ts INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS idx_throttle ON throttle(bucket, ip, ts);
    CREATE TABLE IF NOT EXISTS visits (day TEXT NOT NULL, lang TEXT NOT NULL, n INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (day, lang));
    CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, sort INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1, data_json TEXT NOT NULL DEFAULT '{}'
    );
    CREATE INDEX IF NOT EXISTS idx_items_type ON items(type, sort);
    CREATE TABLE IF NOT EXISTS blocks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, room_slug TEXT NOT NULL, from_date TEXT NOT NULL, to_date TEXT NOT NULL, note TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS price_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT, room_slug TEXT NOT NULL, from_date TEXT NOT NULL, to_date TEXT NOT NULL,
        price INTEGER NOT NULL, label TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, admin_id INTEGER, username TEXT NOT NULL DEFAULT '',
        action TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '', ip TEXT NOT NULL DEFAULT ''
    );
    ");
    $has = function (string $table, string $col) use ($p): bool {
        foreach ($p->query("PRAGMA table_info($table)") as $r) {
            if ($r['name'] === $col) {
                return true;
            }
        }
        return false;
    };
    if (!$has('admins', 'role')) {
        $p->exec("ALTER TABLE admins ADD COLUMN role TEXT NOT NULL DEFAULT 'admin'");
    }
    if (!$has('requests', 'est_total')) {
        $p->exec('ALTER TABLE requests ADD COLUMN est_total INTEGER NOT NULL DEFAULT 0');
    }
    if (!$has('requests', 'source')) {
        $p->exec("ALTER TABLE requests ADD COLUMN source TEXT NOT NULL DEFAULT 'direct'");
        $p->exec("ALTER TABLE requests ADD COLUMN ext_uid TEXT NOT NULL DEFAULT ''");
    }
    if (!$has('requests', 'utm_source')) {
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'referrer', 'attrib'] as $c) {
            $p->exec("ALTER TABLE requests ADD COLUMN $c TEXT NOT NULL DEFAULT ''");
        }
    }
    $p->exec("CREATE TABLE IF NOT EXISTS events (
        day TEXT NOT NULL, type TEXT NOT NULL, label TEXT NOT NULL DEFAULT '', n INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (day, type, label)
    )");
    $p->exec("CREATE TABLE IF NOT EXISTS ical_feeds (
        id INTEGER PRIMARY KEY AUTOINCREMENT, room_slug TEXT NOT NULL, platform TEXT NOT NULL, url TEXT NOT NULL,
        last_sync TEXT NOT NULL DEFAULT '', last_status TEXT NOT NULL DEFAULT '', last_count INTEGER NOT NULL DEFAULT 0
    )");
    $p->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_req_ext ON requests(source, ext_uid) WHERE ext_uid <> ""');
}

// ---------- availability & pricing ----------
/** Nightly price for a room on a given date (seasonal rule wins over base price). */
function price_on(array $room, string $date): int
{
    $st = db()->prepare('SELECT price FROM price_rules WHERE room_slug = ? AND from_date <= ? AND to_date >= ? ORDER BY id DESC LIMIT 1');
    $st->execute([$room['slug'], $date, $date]);
    $v = $st->fetchColumn();
    return $v !== false ? (int)$v : (int)$room['price'];
}

/** Total for [in, out) in EGP, per room (or per bed for dorms: x guests). */
function quote_total(array $room, string $in, string $out, int $guests = 1): int
{
    $total = 0;
    for ($d = new DateTime($in), $e = new DateTime($out); $d < $e; $d->modify('+1 day')) {
        $total += price_on($room, $d->format('Y-m-d'));
    }
    return $room['unit'] === 'bed' ? $total * max(1, $guests) : $total;
}

/** Whether a room type can take the stay: not blocked, and confirmed bookings below quantity (rooms only). */
function room_available(array $room, string $in, string $out): bool
{
    $p = db();
    $b = $p->prepare('SELECT COUNT(*) FROM blocks WHERE room_slug = ? AND from_date < ? AND to_date >= ?');
    $b->execute([$room['slug'], $out, $in]);
    if ((int)$b->fetchColumn() > 0) {
        return false;
    }
    if ($room['unit'] === 'bed') {
        return true;
    }
    $c = $p->prepare("SELECT COUNT(*) FROM requests WHERE room_slug = ? AND status = 'confirmed' AND checkin < ? AND checkout > ?");
    $c->execute([$room['slug'], $out, $in]);
    return (int)$c->fetchColumn() < (int)$room['qty'];
}

function audit(string $action, string $detail = ''): void
{
    db()->prepare('INSERT INTO audit(at, admin_id, username, action, detail, ip) VALUES(?,?,?,?,?,?)')
        ->execute([gmdate('Y-m-d H:i:s'), $_SESSION['admin_id'] ?? null, (string)($_SESSION['admin_user'] ?? ''), $action, mb_substr($detail, 0, 500), client_ip()]);
}

function items(string $type, bool $onlyActive = true): array
{
    $st = db()->prepare('SELECT * FROM items WHERE type = ?' . ($onlyActive ? ' AND active = 1' : '') . ' ORDER BY sort, id');
    $st->execute([$type]);
    return array_map(function ($r) {
        $r['d'] = json_decode($r['data_json'], true) ?: [];
        return $r;
    }, $st->fetchAll());
}

// ---------- helpers ----------
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function setting(string $k, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT k, v FROM settings') as $r) {
            $cache[$r['k']] = $r['v'];
        }
    }
    return $cache[$k] ?? $default;
}

function set_setting(string $k, string $v): void
{
    $st = db()->prepare('INSERT INTO settings(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    $st->execute([$k, $v]);
}

/** Pick a translation from a JSON map, falling back to English then Arabic then anything. */
function tr_json(?string $json, string $lang): string
{
    $m = json_decode($json ?: '{}', true) ?: [];
    foreach ([$lang, 'en', 'ar'] as $l) {
        if (isset($m[$l]) && trim((string)$m[$l]) !== '') {
            return (string)$m[$l];
        }
    }
    return $m ? (string)reset($m) : '';
}

function enabled_langs(): array
{
    $sel = array_filter(explode(',', setting('enabled_langs', implode(',', array_keys(LANGS)))));
    $out = array_values(array_filter($sel, fn($l) => isset(LANGS[$l])));
    return $out ?: ['ar', 'en'];
}

function ui(string $lang): array
{
    static $c = [];
    if (!isset($c[$lang])) {
        $load = function (string $code): array {
            $out = [];
            foreach (['', '.extra'] as $suffix) {
                $f = ROOT . '/app/lang/' . $code . $suffix . '.php';
                if (is_file($f)) {
                    $out = array_merge($out, require $f);
                }
            }
            return $out;
        };
        $c[$lang] = $lang === 'en' ? $load('en') : array_merge($load('en'), $load($lang));
    }
    return $c[$lang];
}

function money(int $n, string $lang): string
{
    $cur = ui($lang)['currency'] ?? 'EGP';
    return number_format($n) . ' ' . $cur;
}

// ---------- security ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && $t !== '' && hash_equals(csrf_token(), $t);
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Returns true if allowed; records the hit. */
function throttle(string $bucket, int $max, int $windowSec): bool
{
    $p = db();
    $ip = client_ip();
    $now = time();
    $p->prepare('DELETE FROM throttle WHERE ts < ?')->execute([$now - 86400]);
    $st = $p->prepare('SELECT COUNT(*) FROM throttle WHERE bucket = ? AND ip = ? AND ts > ?');
    $st->execute([$bucket, $ip, $now - $windowSec]);
    if ((int)$st->fetchColumn() >= $max) {
        return false;
    }
    $p->prepare('INSERT INTO throttle(bucket, ip, ts) VALUES(?, ?, ?)')->execute([$bucket, $ip, $now]);
    return true;
}

function security_headers(bool $admin = false): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    $frame = "frame-src https://www.google.com https://maps.google.com";
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; $frame; base-uri 'self'; form-action 'self' https://wa.me");
    if ($admin) {
        header('Cache-Control: no-store');
    }
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['m' => $msg, 't' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Safe image upload. Returns the public path (e.g. /uploads/ab12.jpg) or throws. */
function save_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed');
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('File too large (max 8MB)');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || !@getimagesize($file['tmp_name'])) {
        throw new RuntimeException('Only JPG, PNG or WebP images are allowed');
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('Could not store file');
    }
    make_thumb(UPLOAD_DIR . '/' . $name);
    return '/uploads/' . $name;
}

function delete_upload(string $path): void
{
    if (preg_match('#^/uploads/[a-f0-9]{20}\.(jpg|png|webp)$#', $path)) {
        @unlink(UPLOAD_DIR . '/' . basename($path));
        @unlink(UPLOAD_DIR . '/' . preg_replace('/\.(jpg|png|webp)$/', '-640.jpg', basename($path)));
    }
}

/** Make a 640px-wide JPEG copy next to an upload (used by cards and galleries for speed). */
function make_thumb(string $file): void
{
    $thumb = preg_replace('/\.(jpg|png|webp)$/', '-640.jpg', $file);
    if ($thumb === $file || !function_exists('imagecreatefromstring')) {
        return;
    }
    $im = @imagecreatefromstring((string)file_get_contents($file));
    if (!$im) {
        return;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w > 640) {
        $im = imagescale($im, 640, (int)round($h * 640 / $w)) ?: $im;
    }
    imagejpeg($im, $thumb, 78);
}

/** Thumbnail URL for an uploaded image if it exists, else the original. */
function thumb(string $path): string
{
    if (preg_match('#^/uploads/([a-f0-9]{20})\.(jpg|png|webp)$#', $path, $m) && is_file(UPLOAD_DIR . '/' . $m[1] . '-640.jpg')) {
        return '/uploads/' . $m[1] . '-640.jpg';
    }
    return $path;
}

/** Display-only price hint in USD or EUR using admin-set rates (EGP per unit). Empty for Arabic or when no rate is set. */
function approx(int $egp, string $lang): string
{
    if ($lang === 'ar' || $egp <= 0) {
        return '';
    }
    $eur = in_array($lang, ['de', 'fr', 'it', 'es'], true);
    $rate = (float)setting($eur ? 'rate_eur' : 'rate_usd');
    if ($rate <= 0) {
        return '';
    }
    return '≈ ' . ($eur ? '€' : '$') . number_format($egp / $rate, 0);
}

require_once __DIR__ . '/ical.php';
