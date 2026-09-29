# Database Viewer 0.4.0

Database Viewer embeds GreyHarbour's hardened Outerbase Studio fork in Pelican Panel. Database credentials remain on the Panel server and never enter HTML, JavaScript, the iframe URL, or Studio.

The plugin reauthorizes every request and derives one of two server-side modes:

- Server owners and subusers with both `database.read` and `database.update` receive **Full SQL access**.
- Subusers with `database.read` alone receive **Read-only SQL access**.

Full-access users may execute MariaDB reads, writes, DDL, stored-program statements, and explicit transaction control. General batches contain 1–100 independently prepared statements, run in order on one connection, and stop at the first error. They are non-atomic unless the submitted SQL explicitly starts and completes a transaction; an earlier auto-committed write or DDL operation is not rolled back when a later statement fails.

Read-only users may execute one `SELECT`, read CTE, `SHOW`, `DESCRIBE`, `DESC`, or `EXPLAIN` statement at a time. Pelican rejects ambiguous or side-effecting read syntax and also executes accepted statements inside a MariaDB read-only transaction that is always rolled back. The selected MariaDB account remains the final privilege boundary for every mode.

The fixed, parameterized six-statement schema bootstrap remains available to both modes so Studio can discover schemas, tables, columns, constraints, foreign keys, and triggers. The diagnostic `SELECT 1` and current-database query remain fixed operations.

## SQL assistant

The SQL assistant uses a managed Cloudflare Workers AI model through the channel-bound parent bridge. Studio sends the user's instruction and visible schema to Pelican, which reauthorizes the viewer before calling the protected Worker endpoint. The shared token remains server-side and the Worker model is fixed to `@cf/meta/llama-3.3-70b-instruct-fp8-fast`.

AI-generated SQL receives no special privilege. It runs only after the user submits it and is subject to the same Full SQL access or Read-only SQL access mode, request limits, MariaDB privileges, and audit metadata as manually entered SQL.

## Requirements and installation

- Pelican Panel `1.0.0-beta38`
- PHP 8.3 or newer with `pdo_mysql`
- the matching GreyHarbour Studio build with database-scoped MySQL embeds
- private network reachability from Panel to the selected MariaDB server

Extract `database-viewer-0.4.0.zip` so the manifest is at `/var/www/pelican/plugins/database-viewer/plugin.json`, then run from the Panel directory:

```sh
php artisan p:plugin:install database-viewer
php artisan optimize:clear
```

Restart long-lived PHP-FPM, Octane, Horizon, and queue worker processes used by the installation. No Composer dependency, npm build, migration, or copied database credential is required.

The optional settings default to the GreyHarbour Studio deployment:

```dotenv
DATABASE_VIEWER_STUDIO_ORIGIN=https://studio.greyharbour.net
DATABASE_VIEWER_AI_TOKEN=<same random token stored as the Worker's DATABASE_VIEWER_AI_TOKEN secret>
```

The Studio setting must be one exact lowercase HTTPS origin. Clear Laravel caches and restart long-lived processes after changing either value.

## Authorization, limits, and errors

Viewer and broker routes resolve the server by `uuid_short`, require normal tenant access and `database.read`, and retrieve the database through that server. Session authentication, two-factor middleware, CSRF protection, throttling, suspended-server denial, and per-request authorization remain active. Revoking `database.update` downgrades the next request to read-only; revoking `database.read` denies it.

The browser bridge validates the exact Studio origin, iframe window, random channel, per-document nonce, request ID, operation, and payload. The limits are:

- 64 KiB per SQL statement;
- 100 statements per full-access batch;
- 1 MiB raw database request;
- 5 MiB serialized database response;
- 3-second connection timeout;
- 30-second MariaDB statement timeout.

Connections are non-persistent and PDO native multi-statements are disabled. Results are read incrementally. Oversized, binary-invalid, or unsupported results fail as a whole without returning partial rows. MariaDB statement failures may return a bounded SQLSTATE/vendor diagnostic; connection and internal failures remain generic. Diagnostics and logs omit SQL text, rows, credentials, DSNs, PHP paths, AI prompts, and AI responses.

AI requests allow 1–12 `system`, `user`, or `assistant` messages, with 24 KiB combined content, a 32 KiB raw request, and a 16 KiB generated response. The AI endpoint is throttled separately to 10 requests per minute. Cloudflare receives the visible schema, conversation, selected SQL, and instruction when the assistant is used.

## Routes

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/database-viewer/servers/{server}/databases/{database}` | authorized viewer page |
| POST | `/database-viewer/servers/{server}/databases/{database}/query` | fixed and general SQL broker |
| POST | `/database-viewer/servers/{server}/databases/{database}/ai` | authenticated Workers AI broker |
| GET | `/database-viewer/assets/{asset}` | allowlisted bridge assets |

The iframe URL contains only the random channel and database name. The Panel CSRF token stays on the parent origin.

## Development and release

From the plugins repository root:

```sh
PELICAN_PATH=/path/to/panel /path/to/panel/vendor/bin/phpunit -c database-viewer/phpunit.xml --fail-on-notice
node --test database-viewer/tests/*.test.mjs
python -m unittest tools.test_package_database_viewer
python tools/package-database-viewer.py
```

The packager uses an immutable runtime allowlist and fixed timestamps. It creates `dist/database-viewer-0.4.0.zip` and a SHA-256 sidecar. Tests, plans, caches, tools, local configuration, and dependencies are excluded.

## Manual check

Import the archive into a disposable compatible Panel, clear caches, and restart long-lived processes. Verify owner and read+update users can create, read, update, and drop a disposable table. Verify a read-only user can run `SELECT` and `SHOW` but receives a policy error for writes. Verify explicit rollback, a failing batch after an auto-committed statement, permission revocation, wrong channels, missing CSRF, response limits, and an invalid AI token.

The automated suite verifies policy, authorization, execution, serialization, safe errors, bridge isolation, and packaging. It does not prove production network reachability or the selected database user's grants. See [verification.md](verification.md) for recorded evidence and [source-inspection.md](source-inspection.md) for integration points.
