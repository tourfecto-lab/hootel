<?php
// Dev router for:  php -S 127.0.0.1:8765 -t public router.php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$f = __DIR__ . '/public' . $p;
if ($p !== '/' && is_file($f) && !str_ends_with($p, '.php')) {
    return false; // serve static file
}
require __DIR__ . '/public/index.php';
