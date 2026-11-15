<?php
// Backfill 640px thumbnails for every existing upload:  php tools/make_thumbs.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$n = 0;
foreach (glob(UPLOAD_DIR . '/*.{jpg,png,webp}', GLOB_BRACE) as $f) {
    if (str_ends_with($f, '-640.jpg')) { continue; }
    make_thumb($f); $n++;
}
echo "$n thumbnails checked/created\n";
