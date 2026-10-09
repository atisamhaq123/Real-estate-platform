# Veyra Estates

See [VERIFICATION.md](VERIFICATION.md) for the executed local acceptance checks.

A core PHP 8.2+ application with MySQL, PDO, HTML/CSS, vanilla JavaScript, and a separate Workerman WebSocket process. Built in this real estate directory; no furniture website files are touched.

## Quick start on Windows / WAMP

Requirements: PHP 8.2 or later with PDO MySQL, fileinfo, mbstring, OpenSSL and sockets; MySQL 8+ using InnoDB; Composer 2. The local build was exercised on PHP 8.3.28, MySQL 8.4.7, and Workerman 5.2.3.

Open PowerShell in this directory:

```powershell
$php = 'C:\wamp64\bin\php\php8.3.28\php.exe'
Copy-Item .env.example .env
# Edit .env for your database credentials and application URL.
composer install
& $php bin/migrate.php
& $php bin/seed.php
& $php bin/seed-properties.php
& $php -S 127.0.0.1:8080 -t public public/router.php
```

If Composer is not in PATH, the downloaded local helper can be used: `& $php tools/composer.phar install`. The helper is ignored by source control; obtain Composer from https://getcomposer.org/download/ on a fresh checkout.

In a second PowerShell window:

```powershell
$php = 'C:\wamp64\bin\php\php8.3.28\php.exe'
& $php bin/realtime.php start
```

Open http://127.0.0.1:8080. Keep `APP_URL` exactly aligned with the browser origin (do not interchange localhost and 127.0.0.1). Windows runs one Workerman process; stop it with Ctrl+C. The PHP server never holds a long-running stream. Sessions, files, and logs are written inside `storage/`, avoiding WAMP's global temp path.

For WAMP Apache, configure a virtual host whose **DocumentRoot is this project's public directory**, enable mod_rewrite, and permit `.htaccess` overrides. Update APP_URL and WS_URL accordingly. Do not expose the project root as a web directory.

## Linux setup

Install PHP 8.2+, php-mysql, php-mbstring, php-xml, php-curl, fileinfo, sockets and Composer. Workerman on Linux uses pcntl/posix for worker management.

```sh
cp .env.example .env
composer install --no-dev --prefer-dist
php bin/migrate.php
php bin/seed.php
php bin/seed-properties.php
php -S 127.0.0.1:8080 -t public public/router.php
# Separate terminal:
php bin/realtime.php start
```

For a deployed installation, use Nginx/Apache + PHP-FPM with only `public/` exposed, an unprivileged database account, HTTPS, and a supervised worker. Proxy `/ws` to the worker and set `WS_URL=wss://your-origin/ws`, `APP_URL=https://your-origin`, and `WS_LISTEN=127.0.0.1:8081`. Give the application user write access only to `storage/`. Use restrictive file permissions; back up the database and private files together. Deployment, reverse proxy, mail, and payment integrations have not been verified here. This is not a production-readiness certification.

## Accounts

Public registration at `/register` creates both buyer and seller memberships. Agent applications are submitted in Account & preferences. Approval and credential verification are separate administrator actions. No public form can grant administrator access.

Local demo seeding creates `admin@aurelle.demo`, `buyer@aurelle.demo`, `seller@aurelle.demo`, `elena@aurelle.demo`, `james@aurelle.demo`, and `sofia@aurelle.demo`. A random password is printed once and stored in ignored, private `storage/demo-accounts.txt`. Never seed a production database. Seeded profiles are fictional, initially unverified, and imagery is illustrative.

For a fresh non-demo installation, skip seed and create an administrator locally:

```sh
php bin/create-admin.php your-email@example.com "Your Name"
```

The command prompts for a password (input is visible in that terminal). A non-demo installation also needs categories, amenities, services, and content populated through the administrative tools or migrations before submitting properties. Use environment `APP_ENV=production` to disable demo seeding and local reset-link files.

Password reset links expire after 30 minutes, are hashed in MySQL, and become single-use on password change. With no mail adapter, local reset links are stored in `storage/mail/` and a delivery-failure record is created; the UI never claims an email was sent. Do not use this operator-mediated reset flow for public production accounts.

## Working workflows

- Public: luxury homepage, sale/rental search with all filters and pagination, shareable URLs, synchronized schematic markers/list, up to four comparisons, privacy-aware details, galleries/floor plans, guides, agent directory/profiles, seller consultations, service requests, journal, FAQs, contact, and editable mortgage estimates.
- Customer: favorites, named shared collections, invitations to registered collaborators, property comments, group conversations, saved searches and in-app alerts, private documents with sharing/revocation, appointments, nonbinding proposal revisions, checklists, and notification/presence preferences.
- Seller: drafts, media upload/order/cover, submission, approval/rejection history, agent contact, viewing windows/open houses, price-change requests, valuation requests, proposal activity, and measured listing activity. Detail-page loads, current favorite rows, and conversation openings are the defined metrics; they are not unique visitor analytics.
- Agent: admin-approved access, editable reviewed profile, assigned properties, lead inbox/pipeline, private notes, follow-up tasks, appointments, customer messages and proposal records. Credential changes remove verification until renewed review.
- Admin: accounts/suspension, agent approval/verification/revocation, listing review/assignment/archive, leads/assignment history, appointments, proposal revisions, price review, provider approval, service catalog/quotations/appointments, invoices/manual payments/refunds/credits, reviews/reports, taxonomy, neighborhood/content/business details, notification templates/failures, audit records, and invoice CSV export.
- Messaging: database persistence before broadcast, authenticated expiring opaque socket tokens, server-authorized channels, unread counters, delivered/read receipts, typing, opt-in presence, controlled attachments, search/older pages, duplicate-send protection, block/report, reconnection and missed-message recovery. Channels recheck suspension, revoked roles, assignment changes, and removed collaborators. HTTP POST is the authoritative send path; the separate worker broadcasts committed messages. The worker can also persist an authorized `send` frame. Database outbox scanning is 500 ms; WebSocket delivery is genuine, not a simulated client chat.
- Billing: service charges only, integer minor units, transaction locking, unique payment/refund references, overpayment/refund limits, preserved invoice/payment/refund/credit history. Manual records require an administrator and do not execute a financial transfer. No card data or property escrow is collected.

Listing graph: draft → submitted → published → under_offer → sold/rented, with rejection, withdrawal, and archival paths. Every significant edit returns a published listing to review. An approved agent must be assigned before publication. Terminal sale/rental states require administrator action; accepted proposals do not change ownership or imply completion.

Viewing graph: requested → confirmed → completed/cancelled/no_show. Bookings store UTC and the original IANA time zone. A one-hour slot is reserved while requested or confirmed; listing and agent rows are locked to avoid conflicting reservations. Future viewings cannot be recorded completed.

Proposal graph: submitted → acknowledged/countered → accepted/rejected/withdrawn. Revisions are append-only; users cannot accept their own latest terms. Acceptance creates a configurable progress checklist and records agreement in principle only.

## Configuration and disabled integrations

`.env.example` documents DB, origin, worker, mail, SMS, map, payment, and tour configuration. Credentials are not committed. The current build uses USD prices and monthly rentals. The map is deliberately labeled **schematic / approximate, not a street map**. A real geocoding/tile provider adapter remains to be connected; no unauthorized listing feed or market-data scraping is used.

Email/SMS sending, hosted online checkout, and signed provider webhooks are **disabled**. Entering credentials alone does not enable an unimplemented adapter. No live valuation, imported market statistics, live lender rates, or automated provider refunds are claimed. Integrations must be implemented and verified with the provider's authorized APIs, signature checks, and idempotent webhook handling before enabling collection. The schema reserves webhook event IDs for that extension.

Support must be explicitly assigned by an administrator. Ordinary admins cannot browse private messages or download customer documents. Report review requires both admin and restricted support membership; the access is audited. Report review currently shows up to the most recent 100 messages in the reported conversation; there is no broad private-message explorer.

Run saved-search checks manually, via Windows Task Scheduler, or cron:

```sh
php bin/alerts.php
```

No task has been scheduled automatically. Notifications are in-app only. Notification template storage is available for future delivery adapters. Service-provider approval is a platform review action, not a claim of licensing or insurance verification.

## Tests

```powershell
& $php tests/run.php
```

Each run creates its own randomly named `aurelle_test_...` database, applies migrations, seeds fictional records, and leaves it for inspection. The application database is never used. The generated database name is recorded in ignored `tests/results/database.txt`. The tests exercise ownership/roles, actual InnoDB rollback/foreign keys, publication/redaction, collection permissions, bookings, proposal history, message idempotency/receipts/blocking, token expiry/suspension, document authorization, payment deduplication, and refund limits.

Optional browser tests need Node and local Chrome:

```powershell
npm.cmd install --prefix tools
$env:DB_DATABASE = Get-Content tests/results/database.txt
$env:APP_URL = 'http://127.0.0.1:8082'
$env:WS_URL = 'ws://127.0.0.1:8083'
& $php -S 127.0.0.1:8082 -t public public/router.php
# Second terminal, same DB_DATABASE and APP_URL:
$env:WS_LISTEN = '127.0.0.1:8083'
& $php bin/realtime.php start
# Third terminal:
node tests/browser.mjs
node tests/workflows.mjs
```

Set `TEST_URL` and `CHROME_PATH` to override defaults. The browser suite checks public/dashboard/admin routes, filtering/comparison/calculator, mobile overflow/navigation, two independent signed-in WebSocket sessions, read receipts, persisted recovery/deduplication, and an explicitly labeled polling fallback. Screenshots are saved under ignored `tests/results/`.

## Source organization

`public/` front controller, router, assets; `app/Controllers/` request handlers; `app/Services/` authorized domain operations; `app/Database.php` PDO access; `templates/` escaped views; `database/migrations/` normalized schema; `bin/` setup, alerts, worker, admin commands; `storage/` private uploads, media, sessions, reset links, and logs; `tests/` isolated acceptance checks. All POST actions require CSRF; private downloads are authorization checked. Financial and transaction records are not hard-deleted.

## Remaining limits

No external credentials or deployment have been verified. Real street maps, email/SMS transport, online billing/webhooks, listing imports, sophisticated report aggregations, and automated retention/virus scanning remain integration or expansion work. Calendar records appear as a month grid, timezone-labeled appointments, and availability windows; drag-and-drop scheduling is not included. Collection invitations require an existing account. Checklists start from five standard milestones; authorized participants can add milestones. The application has no document antivirus engine, so downloads are attachment-only and upload types are limited to validated images/PDF. This is a substantial working local implementation; it does not assert that every enterprise feature in the original brief is production complete.

Photography and font licensing: see [ASSETS.md](ASSETS.md). WebSocket library documentation: [Workerman](https://manual.workerman.net/doc/en/).

## Expanded demonstration collection

Run `php bin/seed-properties.php` after migrations and the initial demo seed to add 120 fictional properties (126 total with the original six). The collection contains 12 each of House, Apartment, Condo, Villa, Townhouse, Penthouse, Estate, Studio, Duplex, and Loft: 80 sales and 40 monthly rentals, across 12 location collections. Listings include three illustrative gallery photos, amenities, assigned demo agents, and initial price/status/audit records. The import is transactional and records unique seed keys, so rerunning it adds no duplicates and preserves existing listings. It is disabled in production.

Run `php tests/collection.php` for isolated collection, filtering, and pagination checks. The collection reuses bundled licensed stock images; it does not claim 120 distinct photographs or real property offerings.
