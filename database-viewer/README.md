# Database Viewer 0.5.0

Database Viewer embeds GreyHarbour's hardened Outerbase Studio fork in Pelican Panel. Database credentials remain on the Panel server and never enter HTML, JavaScript, the iframe URL, or Studio.

The plugin checks current Pelican permissions on every SQL, AI, and Extend request:

- server owners and subusers with `database.read` plus `database.update` receive **Full SQL access**;
- subusers with `database.read` alone receive **Read-only SQL access**.

Removing `database.update` makes the next request read-only. Removing `database.read` or tenant access blocks SQL, AI, and Extend before MariaDB or the AI Worker is called. The selected MariaDB account remains the final privilege boundary.

## Atomic SQL behavior

Full-access individual queries retain broad SQL support, including DDL such as `CREATE`, `ALTER`, and `DROP`. A manual transaction cannot span HTTP requests because connections are non-persistent.

Studio transaction envelopes are managed differently. Pelican validates the complete 1–100 statement batch before connecting, then allows only `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `REPLACE`, and safe `WITH` forms. Explicit transaction control, DDL, locks, file output, stored programs, administrative statements, and other unknown or implicit-commit families fail before execution with `TRANSACTION_NOT_ATOMIC`.

An accepted batch runs in one MariaDB transaction. Any statement, serialization, aggregate response-size, or commit failure rolls it back. Pelican validates the complete encoded response against the 5 MiB limit before commit. Atomic rollback still depends on every affected table using a transactional storage engine such as InnoDB; MariaDB cannot roll back writes made by a non-transactional engine.

Studio's fixed six-query schema bootstrap remains separate and available to both access modes. Read-only users may also run one `SELECT`, read CTE, `SHOW`, `DESCRIBE`, `DESC`, or `EXPLAIN` query at a time inside a read-only transaction that is always rolled back.

## Temporary viewer sessions

Opening the viewer creates a server-side session with a random channel whose SHA-256 hash is stored in Pelican. The initial lifetime is a 15-minute lease. The page computes its cosmetic countdown from authoritative server time, so browser clock skew does not grant or remove access.

The **Extend 15 minutes** control sets expiry to 15 minutes from the current server time without stacking unused time. A viewer has a two-hour absolute maximum from creation. SQL and AI activity updates audit activity only and never extends authority.

The **Close viewer** control revokes the session immediately and is idempotent. An owner may Close their own viewer even after tenant or database permission is removed. Expired or closed sessions cannot execute or extend. Each user may have at most 20 active viewers; the 21st is rejected without closing an existing viewer.

Historical rows become eligible for cleanup 24 hours after expiry or revocation. Viewer creation attempts one bounded best-effort cleanup pass. Operators may also run:

```sh
php artisan database-viewer:prune-sessions
```

## SQL assistant

The SQL assistant uses the fixed Cloudflare Workers AI model `@cf/meta/llama-3.3-70b-instruct-fp8-fast` through the channel-bound parent bridge. Studio sends the user's instruction and visible schema to Pelican, which reauthorizes the session before calling the protected Worker endpoint. The shared token remains server-side.

AI-generated SQL receives no special privilege. It runs only after the user submits it and remains subject to the current Full SQL access or Read-only SQL access mode, request limits, MariaDB grants, and audit metadata.

## Requirements and installation

- Pelican Panel `1.0.0-beta38`
- PHP 8.3 or newer with `pdo_mysql`
- the matching GreyHarbour Studio build with database-scoped MySQL embeds
- private network reachability from Panel to the selected MariaDB server

Extract `database-viewer-0.5.0.zip` so the manifest is at `/var/www/pelican/plugins/database-viewer/plugin.json`, then run from the Panel directory:

```sh
php artisan p:plugin:install database-viewer
php artisan migrate --force
php artisan optimize:clear
```

The 0.5.0 migration creates the plugin-owned viewer-session table. Restart long-lived PHP-FPM, Octane, Horizon, and queue worker processes after installation. Existing 0.4.0 viewer tabs must be reloaded so they receive a new temporary session.

Optional settings default to the GreyHarbour deployment:

```dotenv
DATABASE_VIEWER_STUDIO_ORIGIN=https://studio.greyharbour.net
DATABASE_VIEWER_AI_TOKEN=<same random token stored as the Worker's DATABASE_VIEWER_AI_TOKEN secret>
```

The Studio setting must be one exact lowercase HTTPS origin. Clear Laravel caches and restart long-lived processes after changing either value.

## Authorization and limits

All routes retain session authentication, two-factor middleware, CSRF protection, throttling, suspended-server denial, server/database relationship checks, and per-request authorization. The browser bridge validates the exact Studio origin, iframe window, random channel, document nonce, request ID, operation, and payload.

- 64 KiB per SQL statement
- 100 statements per managed batch
- 1 MiB raw database request
- 5 MiB complete serialized database response
- 3-second connection timeout
- 30-second MariaDB statement timeout
- 1–12 AI messages, 24 KiB combined AI content, 32 KiB raw AI request, and 16 KiB AI response

PDO native multi-statements and persistent connections are disabled. Results are read incrementally. Safe MariaDB statement diagnostics may be returned, while connection and internal failures remain generic. Logs omit SQL, rows, credentials, DSNs, paths, AI prompts, and AI responses.

## Routes

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/database-viewer/servers/{server}/databases/{database}` | authorized viewer page and temporary-session creation |
| POST | `/database-viewer/servers/{server}/databases/{database}/query` | fixed and general SQL broker |
| POST | `/database-viewer/servers/{server}/databases/{database}/ai` | authenticated Workers AI broker |
| POST | `/database-viewer/servers/{server}/databases/{database}/session/extend` | explicit session extension |
| POST | `/database-viewer/servers/{server}/databases/{database}/session/close` | idempotent session revocation |
| GET | `/database-viewer/assets/{asset}` | allowlisted bridge assets |

The iframe URL contains only the random channel and database name. The Panel CSRF token stays on the parent origin.

## Development and release

From the plugins repository root:

```sh
PELICAN_PATH=/path/to/panel /path/to/panel/vendor/bin/phpunit -c database-viewer/phpunit.xml
node --test database-viewer/tests/*.test.mjs
python -m unittest tools.test_package_database_viewer
python tools/package-database-viewer.py
```

The deterministic packager uses a fixed runtime allowlist and timestamps. It creates `dist/database-viewer-0.5.0.zip` and a SHA-256 sidecar. Tests, plans, caches, local configuration, dependencies, and `database-viewer-ai-token.txt` are excluded.

## Manual check

Import the archive into a disposable compatible Panel, run the migration, clear caches, and restart long-lived processes. Verify a Full user can perform an atomic multi-row edit and can run individual DDL. Confirm Studio's DDL transaction envelopes receive `TRANSACTION_NOT_ATOMIC`. Verify a Read-only user can query but cannot write. Test Extend, the two-hour cap, Close after permission removal, expiry, the 20-viewer limit, pruning, response limits, wrong channels, missing CSRF, and an invalid AI token.

Automated tests use connector/PDO doubles and SQLite for the Panel database. They do not prove production network reachability, live MariaDB transaction behavior, storage-engine configuration, or the selected database user's grants. See [verification.md](verification.md) for recorded evidence and [source-inspection.md](source-inspection.md) for integration points.
