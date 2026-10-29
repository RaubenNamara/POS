# Deployment and maintenance

Target: ordinary PHP/MySQL hosting (cPanel or a small VPS). No Docker, Node or daemons are needed on the server.
The web app is built on your computer and uploaded as static files inside `backend/public`.

## 1. Server requirements

* PHP 8.2+ with extensions: `bcmath`, `pdo_mysql`, `mbstring`, `openssl`, `zip`, `xml`, `fileinfo`, `ctype`, `tokenizer`
* MySQL 8 or MariaDB 10.4+ (InnoDB). Give it a buffer pool of at least 128 MB if you control the server
  (`innodb_buffer_pool_size`); reports get much faster.
* Apache with `mod_rewrite` (plus `mod_deflate`/`mod_brotli` and `mod_headers` for compression and caching), or Nginx.
* HTTPS certificate (cPanel AutoSSL / Let's Encrypt). The app forces HTTPS and uses secure cookies.
* Cron access.

## 2. Build locally

```bash
cd web
npm ci
npm run build          # type-checks, then writes backend/public/{spa.html,assets/,sw.js,...}
cd ../backend
composer install --no-dev --optimize-autoloader
```

## 3. Upload and configure

1. Upload the `backend/` folder (including `vendor/` and the built `public/`) to the server, e.g. `~/shop/backend`.
2. Point the domain's **document root at `backend/public`** (cPanel → Domains → Document Root). Nothing else in
   `backend/` should be web-accessible.
3. Create the database and a user with privileges on it only.
4. `cp .env.example .env`, fill in `APP_URL`, `DB_*`, `SANCTUM_STATEFUL_DOMAINS` (your domain, no scheme),
   `SHOP_OWNER_*`. Keep `APP_DEBUG=false`.
5. Over SSH (or cPanel Terminal):

```bash
php artisan key:generate --force
php artisan migrate --force --seed       # creates tables, payment methods, owner account
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
chmod -R ug+rw storage bootstrap/cache
```

Never run `DemoSeeder` on a live shop (it refuses to in production anyway).

### Nginx instead of Apache

```nginx
root /home/shop/backend/public;
index index.php;
gzip on; gzip_types text/css application/javascript application/json image/svg+xml application/manifest+json;
location /assets/ { expires 1y; add_header Cache-Control "public, immutable"; try_files $uri =404; }
location ~ ^/(sw\.js|spa\.html|manifest\.webmanifest)$ { add_header Cache-Control "no-cache"; }
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php8.2-fpm.sock; fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; }
```

## 3b. Google sign-in, Google Maps and Flutterwave

Set these in `.env`, then `php artisan config:cache`. Each feature switches itself off cleanly while its keys are empty.

| Setting | Where to get it | Notes |
|---|---|---|
| `GOOGLE_CLIENT_ID` | Google Cloud Console → APIs & Services → Credentials → OAuth client ID (Web) | Add your site (`https://shop.example.com`) under *Authorised JavaScript origins*. Customers only; staff always use passwords. |
| `GOOGLE_MAPS_BROWSER_KEY` | Same console → API key with **Maps JavaScript API** enabled | Restrict to *HTTP referrers* `https://shop.example.com/*`. Shows the map pin picker. |
| `GOOGLE_MAPS_SERVER_KEY` | A second API key with **Geocoding API** enabled | Restrict to your server's IP. **Required for pay on delivery**: it is how the server confirms a pin is in Kampala/Wakiso. Without it, pay on delivery is not offered (fail-safe). Results are cached per ~11 m for 30 days to keep costs low. |
| `FLUTTERWAVE_PUBLIC_KEY`, `FLUTTERWAVE_SECRET_KEY` | dashboard.flutterwave.com → Settings → API keys | Start with **test** keys; switch to live keys after a test payment works. |
| `FLUTTERWAVE_WEBHOOK_HASH` | Any long random string you choose | Enter the same value in Flutterwave → Settings → Webhooks → *Secret hash*, with URL `https://shop.example.com/api/webhooks/flutterwave`. |
| `COD_DISTRICTS` | - | Districts where pay on delivery is offered (default `Kampala,Wakiso`). |
| `COD_FALLBACK_RADIUS_KM` | - | **Keep 0 in production.** Local testing only (distance from `SHOP_LAT`/`SHOP_LNG` instead of Google). |

Payment rules: pay on delivery only when the server places the *delivery pin* in a COD district; online payment
(Flutterwave: MTN/Airtel Mobile Money, cards) anywhere. An order is marked paid only after the server re-checks the
transaction with Flutterwave (amount, currency, reference). Unpaid online-payment orders hold stock for
*Settings → Hold stock for online payment* minutes (default 60), then release it, the cron job re-checks
Flutterwave before cancelling. Amount mismatches and payments that arrive after an order expired raise a
notification for staff to refund or re-create the order.

## 4. Cron (queue + backups + cleanup)

Add **one** cron entry:

```
* * * * * cd /home/USER/shop/backend && php artisan schedule:run >> /dev/null 2>&1
```

It runs (see `routes/console.php`): a short-lived queue worker every minute (large report exports), the daily
database backup at 02:30, and cleanup of expired tokens and old export files.

## 5. Backups and restore

* `php artisan shop:backup` writes a gzip'd, consistent dump (`mysqldump --single-transaction`, no locking, the shop
  keeps selling) to `storage/app/backups`, keeping the newest 14. Set `MYSQLDUMP_PATH` if `mysqldump` isn't on PATH.
* **Copy backups off the server** (cPanel backup, rclone to cloud storage, or download weekly). A backup on the same
  disk does not survive a disk failure.
* Also back up `storage/app/public` (product photos and logo).
* Restore:

```bash
gunzip -c storage/app/backups/shop_YYYYMMDD_HHMMSS.sql.gz | mysql -u USER -p DBNAME
```

Restoring was tested: every table's row count and all CHECK constraints come back. **Test a restore into a
scratch database every few months**, then run *Reports → Reconciliation check* on it.

## 6. Updating

```bash
# locally
cd web && npm run build && cd ../backend && composer install --no-dev -o
# upload changed files, then on the server:
php artisan down --retry=30
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

Run `php artisan shop:backup` before migrating. The service worker updates tills and phones automatically on their
next visit, and sales queued offline survive the update.

## 7. Monitoring and health

* `GET /up` returns 200 when the app is up; point an uptime monitor (e.g. UptimeRobot) at it.
* Logs: `storage/logs/laravel-YYYY-MM-DD.log` (`LOG_CHANNEL=daily`). Failed backups are logged as errors.
* In the app: *Offline sales review* (conflicts), notifications bell (low stock, new online orders),
  *Reports → Reconciliation check* (should always say everything ties out), *Audit log* (who changed what).
* To profile on the server temporarily, set `SERVER_TIMING=true` in `.env`: API responses then include a
  `Server-Timing` header and slow queries are logged. Turn it off afterwards.

## 8. Security checklist

* `APP_DEBUG=false`, HTTPS on, `SESSION_SECURE_COOKIE=true`.
* Document root is `backend/public` only; `.env` is not downloadable.
* Change the owner password after first login and give each staff member their own account with the least role
  they need. Disable accounts of people who leave (Staff page); this immediately ends their sessions and tokens.
* Login is rate-limited (10/min per account+IP); checkout and API calls are rate-limited too.
* The database user should have rights only on the shop database.

## 9. Mobile apps

Not built yet (the Flutter SDK was not available where this was developed). The API is ready: apps log in with
`POST /api/auth/login` and a `device_name` to receive a bearer token, then use the same endpoints and permissions as
the web app. Build steps (`flutter build apk --release` / `flutter build ipa`) will be added together with the app.
