<?php
/**
 * Link the photos already stored in public/uploads to the gallery, rooms and hero image,
 * and set the confirmed amenities. Safe to run more than once.   php tools/restore_content.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$data = json_decode((string)file_get_contents(dirname(__DIR__) . '/seed/content.json'), true);
if (!$data) { fwrite(STDERR, "seed/content.json missing or invalid\n"); exit(1); }
$pdo = db();
$have = fn(string $p) => $p !== '' && is_file(UPLOAD_DIR . '/' . basename($p));

$pdo->exec('DELETE FROM gallery');
$st = $pdo->prepare('INSERT INTO gallery(path, sort) VALUES(?, ?)');
$g = 0;
foreach ($data['gallery'] as $i => $path) { if ($have($path)) { $st->execute([$path, $i]); $g++; } }

$up = $pdo->prepare('UPDATE rooms SET photos_json = ? WHERE slug = ?');
$r = 0;
foreach ($data['rooms'] as $slug => $photos) {
    $ok = array_values(array_filter($photos, $have));
    $up->execute([json_encode($ok), $slug]);
    $r += count($ok);
}
if ($have($data['hero'] ?? '')) { set_setting('hero_image', $data['hero']); }

$am = $pdo->prepare('INSERT INTO amenities(akey, icon, confirmed, sort, label_json) VALUES(?,?,?,?,?)
    ON CONFLICT(akey) DO UPDATE SET confirmed = excluded.confirmed, icon = excluded.icon, sort = excluded.sort, label_json = excluded.label_json');
foreach ($data['amenities'] as $a) { $am->execute([$a['akey'], $a['icon'], (int)$a['confirmed'], (int)$a['sort'], $a['label_json']]); }

echo "gallery: $g photos | room photos: $r | amenities: " . count($data['amenities']) . " | hero: " . ($have($data['hero'] ?? '') ? 'set' : 'missing') . "\n";
