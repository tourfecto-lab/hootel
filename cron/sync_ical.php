<?php
// Pull OTA calendars. Cron (every 15 minutes):  php /path/to/kings-palace-site/cron/sync_ical.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
foreach (ical_sync_all() as $id => $msg) { echo "feed $id: $msg\n"; }
