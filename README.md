# Duka Shop, inventory, POS and online shop

One system for a retail shop: an online storefront, a fast in-store till (POS) that keeps working offline,
and a back office for stock, buying, money and reports. Everything runs on ordinary PHP/MySQL hosting.

```
shop/
├── backend/   Laravel 12 API (PHP 8.2+, MySQL/MariaDB). Also serves the built web app.
├── web/       Vue 3 + TypeScript + Vite + Tailwind 4 single-page app: storefront, /pos, /admin. Installable PWA.
└── e2e/       Playwright end-to-end checks (real browser, throttled phone profile).
```

## What it does

| Area | Highlights |
|---|---|
| **Storefront** | Search by name/brand/model/SKU/barcode, category tree, filters, sort, pagination, product pages with specs and photos, packages (bundles), cart, checkout (delivery or pickup), customer accounts (password or Google), order tracking. |
| **Location & payment** | New customers share GPS or drop a pin on Google Maps. The server checks the district with Google: in Kampala/Wakiso customers may **pay on delivery or pay online**; elsewhere only **online payment (Flutterwave: MTN/Airtel Mobile Money, cards)**. Checked per order against the delivery pin. |
| **POS** (`/pos`) | Barcode scanner and keyboard first (F2 search, F4 customer, F9 pay), live stock, line and cart discounts (cashier cap), split/partial payments, credit sales against customer limits, thermal / A4 / PDF receipts, shifts with cash reconciliation. Offline queue with duplicate-proof sync. |
| **Stock** | Products, categories, brands, images (resized to WebP in the browser), opening stock, adjustments, damaged/missing, stock counts, transfers between locations, full movement history, low-stock alerts. |
| **Buying** | Suppliers, purchase orders, goods received (partial or against no PO), supplier invoices and payments, balances. |
| **Money** | Weighted-average costing, COGS frozen per sale, gross/net profit, expenses, cash flow per payment method, customer debts, supplier balances, inventory capital and potential selling value. |
| **Reports** | P&L, cash flow, daily sales, best sellers, slow moving, cashier performance, returns, debts, purchases, stock movements, low stock, inventory valuation, and a reconciliation check. CSV / Excel / PDF exports (large ones run in the background). |
| **Admin** | Roles (owner, manager, cashier, stock manager, customer), staff accounts, settings (tax, receipt text, offline limits, delivery fee), audit log, offline-conflict review. |

## Business rules that matter

* **The server decides every number.** Clients send product ids, quantities and requested discounts; prices, costs, tax, totals, change and balances are recomputed from the database. All money is `DECIMAL` in MySQL and `bcmath` in PHP, never floats.
* **Stock can't go negative or be sold twice.** Every stock change goes through `InventoryService`: one DB transaction, rows locked in a fixed order (no deadlocks), availability checked before any write, and a `CHECK (quantity >= 0)` constraint as a final guard. Each movement records quantity, reason, user, location, resulting balance and the source document.
* **Idempotent writes.** Sales, returns, goods receipts, payments and expenses carry a client-generated UUID with a unique index. A double click, retry or reconnect returns the original result instead of creating a second one.
* **Bundles use real stock.** A package has no stock of its own; selling one deducts each component, and availability is computed from components.
* **Profit is accrual-correct.** Revenue = completed sales − tax − returns. COGS = weighted-average cost at the moment of sale (frozen on the sale line). Buying stock is capital, not an expense. Credit sales are revenue but not cash. Online orders count when delivered. Damaged returns keep their cost in COGS; count shortfalls are written off at average cost.
* **Payments are never assumed.** A receipt says PAID only when the server has recorded the money. Online orders stay unpaid until staff confirm cash/mobile money on delivery. Offline receipts are printed as PROVISIONAL.

### Offline POS

The till loads its catalogue from IndexedDB, so it opens and searches with no connection. While offline it may
**only** take cash sales up to a configurable amount and queue length (Settings → Till rules). Queued sales survive
restarts and upload automatically. On sync each is checked against current stock: if another till sold the goods in the
meantime, nothing is deducted and the sale is parked under **Offline sales review** for a manager to retry (after a stock
count) or void. Replaying an already-synced sale is reported as a duplicate, never sold twice.

## Running locally

Requirements: PHP 8.2+ (bcmath, pdo_mysql, zip, mbstring), Composer, MySQL 8 / MariaDB 10.4+, Node 20+.

```bash
cd backend
cp .env.example .env            # set DB_*; for local use APP_ENV=local, APP_DEBUG=true, SESSION_SECURE_COOKIE=false
composer install
php artisan key:generate
php artisan migrate --seed      # base data + owner account (password printed once)
php artisan db:seed --class=DemoSeeder   # optional demo catalogue + staff (password DemoPass123)
php artisan storage:link

cd ../web && npm install && npm run build   # builds into backend/public
cd ../backend && php artisan serve           # http://127.0.0.1:8000
```

For front-end development with hot reload run `npm run dev` in `web/` (http://localhost:5173; it proxies `/api` to `:8000`).

Demo logins after `DemoSeeder`: `owner@demo.local`, `manager@demo.local`, `cashier@demo.local`, `stock@demo.local`,
shopper `shopper@demo.local`, all `DemoPass123`.

## Tests

```bash
cd backend
php artisan test                          # 41 feature tests against MariaDB (DB shop_test)
php scripts/concurrency-test.php 24       # real parallel processes racing for the same stock / same sale
bash scripts/latency.sh http://127.0.0.1:8000   # API latency + Server-Timing per endpoint
cd ../e2e && npm install && node flows.mjs http://127.0.0.1:8000   # browser flows on a throttled phone profile
node location-flow.mjs http://127.0.0.1:8000   # sign-up → location → pay-on-delivery rules
node responsive.mjs http://127.0.0.1:8000 ./shots --shots   # 18 pages × 9 screen widths (320 px phone … 4K)
```

Covered: stock deduction for products and bundles; insufficient stock (atomic, no partial deduction); duplicate
submissions; split, partial and credit payments; discount limits; inclusive/exclusive tax; weighted-average cost;
returns (partial, damaged, against credit); P&L, cash flow, inventory and balances against hand-calculated figures;
offline sync, replay and conflicts; role permissions on every protected area; login rate limiting; mobile tokens;
online orders (reservation, cancel restock, revenue on delivery); purchase orders and supplier payments; shift
reconciliation; dashboard cache invalidation; backup and restore.

## Screen sizes

Tested from a 320 px phone to 4K (3840 px): no page scrolls sideways at any width, wide tables scroll inside their
own card, form fields use 16 px text on phones (no iPhone zoom-on-tap), and from 1,800 px up the whole interface
scales (rem-based) so 27" and 4K monitors show larger text and more product columns instead of a narrow strip.

## Measured performance (2026-10-22, this machine)

Storefront on a throttled low-end phone profile (360×740, 4× CPU slowdown, "slow 4G" 150 ms / 1.6 Mbps), Playwright:

| Metric | Result | Target |
|---|---|---|
| Home LCP | ~1.8 s | ≤ 2.5 s |
| Home / product CLS | 0 | ≤ 0.1 |
| Add to cart, click → paint | ~14 ms | INP ≤ 200 ms |
| POS product tap → paint / barcode scan | ~14 ms / ~18 ms | ≤ 200 ms |

JS: 45 KB gzip core (Vue + router + shell); every page is its own chunk (storefront pages 1-3 KB, POS 6.6 KB gzip).
The service worker precaches only the storefront and POS (~280 KB raw), not back-office pages.

API latency (median of 5, local PHP server, MariaDB with XAMPP's default 16 MB buffer pool) on a synthetic dataset of
**10,000 products, 60,000 sales, 150,000 sale lines** (`php artisan shop:perf-seed`):

| Endpoint | Before tuning | After |
|---|---|---|
| Storefront home / search / product (cached) | 16-42 ms | 15-35 ms |
| POS bootstrap (full 10k catalogue) | 345 ms | 85 ms |
| Dashboard | 1,657 ms | 48 ms |
| P&L, one year | 827 ms | ~160 ms |
| Best sellers, one year | 1,125 ms | ~610 ms |
| Slow-moving stock | 5,571 ms | 67 ms |
| Reconciliation check (whole DB) | 92,748 ms | 1,248 ms |

Fixes applied: covering indexes for profit and best-seller aggregates, `products.last_sold_at` maintained on sale,
pre-aggregated reconciliation queries, plain query-builder POS feed, and a dashboard cache keyed on the newest
transaction ids (so it is never stale). These are local measurements: re-measure on the production host; real-user
Core Web Vitals at the 75th percentile need field data from real customers. In debug mode every API response carries a
`Server-Timing` header (query count, DB time) and queries over 100 ms are logged.

## Not built yet

* **Android / iOS apps (Flutter).** The Flutter SDK is not installed on this machine, so no mobile code has been
  written or tested. The API is ready for it: token login (`device_name`), the same role permissions, and the same
  POS endpoints, including offline sync. Meanwhile the web app installs as a PWA on Android and iOS
  (Add to Home Screen).
* **Live Google / Flutterwave keys.** Google sign-in, the map picker, district checks and Flutterwave payments are built
  and tested against simulated Google/Flutterwave responses, but have not run against the real services yet: they
  need your keys (see DEPLOYMENT.md §3b).
* **CDN.** Not configured; static assets have immutable hashed names, so any CDN in front of the domain will cache them correctly.

See [DEPLOYMENT.md](DEPLOYMENT.md) for production setup, backups and maintenance.
