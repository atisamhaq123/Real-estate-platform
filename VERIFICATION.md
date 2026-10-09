# Local verification

Validated on Windows/WAMP with PHP 8.3.28, MySQL 8.4.7, Workerman 5.2.3, and headless local Google Chrome through Playwright.

- **47 database checks passed** (`php tests/run.php`) in a newly created isolated database. Includes foreign-key enforcement, actual rollback, role/owner boundaries, moderation, private location redaction, collection membership, bookings, proposals, private documents, socket-token expiration, role/assignment revocation, message idempotency, manual payment deduplication and refund limits.
- **99 browser checks passed** (`node tests/browser.mjs`) in an isolated test application on ports 8082/8083. Includes public/dashboard/admin routes, mobile widths, filters/comparison/calculator, two independent authenticated WebSocket sessions, broadcast, read receipts, reload/missed-message recovery, duplicate prevention, simulated worker-unavailable polling, CSRF, validated uploads, document sharing/revocation, content changes, CSV export, and appointment calendar.
- **13 workflow browser checks passed** (`node tests/workflows.mjs`) in that isolated application. Includes actual guided submission with land area/tour permissions, upload, private draft access, unauthorized approval denial, approved agent assignment, publication, private-address disclosure, configurable transaction milestones, cover selection, and required re-review.
- PHP syntax checks and JavaScript syntax checks passed. Desktop and mobile screenshots were visually inspected.

The local demonstration application runs at http://127.0.0.1:8080 with its separate worker at ws://127.0.0.1:8081. Private demonstration credentials are in `storage/demo-accounts.txt`. Test records never mutate the demonstration application database. Screenshots and temporary test database names are in ignored `tests/results/`.

Real street maps/geocoding, external mail/SMS, payment collection/provider webhooks, deployment/reverse proxy/TLS, and real imported listings were not tested or connected. These remain explicitly disabled or schematic in the interface. See README.md for integration limitations and Windows/Linux setup.
