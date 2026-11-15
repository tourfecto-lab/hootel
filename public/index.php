<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/site.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim($path, '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!is_file(DATA_DIR . '/site.sqlite')) {
    http_response_code(503);
    echo 'Site not installed. Run: php install.php';
    exit;
}

if ($path === '/robots.txt') {
    header('Content-Type: text/plain');
    echo "User-agent: *\nDisallow: /admin\nDisallow: /api/\nDisallow: /ical/\nSitemap: " . base_url() . "/sitemap.xml\n";
    exit;
}
if ($path === '/sitemap.xml') {
    sitemap();
    exit;
}
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    require dirname(__DIR__) . '/app/admin.php';
    admin_dispatch($path, $method);
    exit;
}
if (preg_match('#^/ical/([a-f0-9]{32})/([a-z0-9\-]+)\.ics$#', $path, $m) && $method === 'GET') {
    if (!hash_equals(ical_token(), $m[1]) || !throttle('ical', 120, 3600)) {
        http_response_code(404);
        exit;
    }
    ical_export($m[2]);
    exit;
}
if ($path === '/api/quote' && $method === 'GET') {
    api_quote();
    exit;
}
if ($path === '/api/event' && $method === 'POST') {
    api_event();
    exit;
}
if ($path === '/request' && $method === 'POST') {
    handle_request_post();
    exit;
}
if ($path === '/') {
    redirect('/' . detect_lang() . '/', 302);
}

if (preg_match('#^/([a-z]{2})(?:/(.*))?$#', $path, $m) && in_array($m[1], enabled_langs(), true)) {
    $lang = $m[1];
    $sub = $m[2] ?? '';
    capture_source();
    track_visit($lang);
    if ($sub === '') {
        render_home($lang);
        exit;
    }
    if ($sub === 'faq') {
        render_faq($lang);
        exit;
    }
    if ($sub === 'contact') {
        render_contact($lang);
        exit;
    }
    if (preg_match('#^rooms/([a-z0-9\-]+)$#', $sub, $r) && ($room = room_by_slug($r[1]))) {
        render_room($lang, $room);
        exit;
    }
    render_404($lang);
    exit;
}
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>404</title><p style="font:16px sans-serif;padding:2em">404 – <a href="/">Home</a></p>';
