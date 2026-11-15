# Kings Palace Hotel site (standalone, separate from the Tourfecto platform)

PHP 8+ and SQLite. No framework, no Composer.

## Run locally
    php install.php                 # once: creates data/site.sqlite, seeds content, prints the admin password ONCE
    php -S 127.0.0.1:8766 -t public router.php
    open http://127.0.0.1:8766/  and  /admin

`php install.php --reset-admin` issues a new admin password.

## Deploy (any PHP host)
Upload the folder. Point the domain's document root at `public/`. `data/` and `app/` must NOT be web-accessible.
Run `php install.php` once on the server (or upload a prepared `data/site.sqlite`). Make `data/` and `public/uploads/` writable.
Then in Admin > Content & settings set the public site URL, WhatsApp number, check-in/out times.

## Add a language
Add an entry to `LANGS` in `app/bootstrap.php` and a file `app/lang/<code>.php` (copy `en.php`), then tick it in Admin > Content.

## OTA calendar sync (Booking.com, Agoda, Expedia, Hostelworld, Airbnb...)
Admin > OTA calendar sync. Paste each platform's iCal link per room type (import) and give the platform the private export link (export).
iCal carries dates only. Platforms without iCal: add bookings by hand there, or use a channel manager.
Cron (every 15 min): `php /path/to/kings-palace-site/cron/sync_ical.php`. The site also refreshes stale feeds before accepting a request.

## Never commit
`data/` (SQLite DB with guest phone numbers and admin hashes). It is git-ignored.

## Auto-deploy
Pushes to main are pulled by the server every minute (see DEPLOY.md). Check `data/deploy.log` on the server.
