# Database Viewer 0.3.0

Database Viewer embeds GreyHarbour's hardened Outerbase Studio fork in Pelican Panel and supplies the fixed, read-only metadata that Studio needs to open its native MySQL editor. Database credentials remain on the Panel server. They never enter HTML, JavaScript, the iframe URL, or Studio.

The SQL assistant uses a managed Cloudflare Workers AI model through the same channel-bound parent bridge. Studio sends the user's instruction and visible database schema to Pelican; Pelican reauthorizes the viewer before calling the protected Worker endpoint. The shared broker token remains server-side and the Worker model is fixed to `@cf/meta/llama-3.3-70b-instruct-fp8-fast`.

## Supported operations

The broker accepts exactly one six-statement transaction, in this order:

1. return the single authorized schema;
2. list tables and views;
3. list columns;
4. list primary, unique, and foreign-key constraints;
5. list foreign-key column usage;
6. list triggers.

It also accepts the diagnostic query `SELECT 1` and the current-database query `SELECT DATABASE() AS db`. `SELECT 1` permits case changes, surrounding whitespace, and one trailing semicolon. The current-database query and all six metadata statements must match their canonical strings exactly. The broker then replaces every accepted request with server-owned SQL before execution. Incoming SQL is never passed to PDO. Row queries, writes, DDL, arbitrary transactions, exports, and dumps are rejected.

The requested database is part of the iframe route. Studio must receive exactly one valid database name on `/embed/mysql?channel=…&database=…`; it uses that name to constrain every metadata statement. Names are accepted only when they contain 1–64 Unicode code points and no control characters, DEL, or replacement characters.

## Requirements and installation

- Pelican Panel `1.0.0-beta38`
- PHP 8.3 or newer with `pdo_mysql`
- the matching Studio build containing database-scoped MySQL embeds
- private network reachability from Panel to the selected MariaDB server

Extract `database-viewer-0.3.0.zip` so the plugin manifest is at `/var/www/pelican/plugins/database-viewer/plugin.json`, then run from the Panel directory:

```sh
php artisan p:plugin:install database-viewer
php artisan optimize:clear
```

No Composer dependency, npm build, migration, or copied database credential is required.

The optional Panel setting defaults to the GreyHarbour Studio deployment:

```dotenv
DATABASE_VIEWER_STUDIO_ORIGIN=https://studio.greyharbour.net
DATABASE_VIEWER_AI_TOKEN=<same random token stored as the Worker's DATABASE_VIEWER_AI_TOKEN secret>
```

It must be one exact lowercase HTTPS origin. Run `php artisan optimize:clear` after changing it.

## Authorization and limits

Both viewer and broker routes resolve the server by `uuid_short`, require normal tenant access and `database.read`, and retrieve the database through that server's relationship. Session authentication, two-factor middleware, CSRF protection, request throttling, suspended-server denial, and per-request authorization remain active. The database password is decrypted only when opening the request-local PDO connection.

The browser bridge validates the exact Studio origin, iframe window, random channel, per-document nonce, request ID, operation, and payload. The per-document nonce prevents an old request from resolving a new Studio document after an iframe reload, even though browsers preserve the iframe's `WindowProxy`. A schema request is limited to six statements, each at most 2,048 UTF-8 bytes. The raw database JSON request is limited to 16 KiB. A serialized database response larger than 5 MiB is rejected. Connections are non-persistent, PDO multi-statements are disabled, and MariaDB receives a three-second statement limit.

AI requests allow 1–12 messages with only `system`, `user`, and `assistant` roles. Combined message content is limited to 24 KiB, the raw request to 32 KiB, and the generated response to 16 KiB. The AI endpoint is separately throttled to 10 requests per minute. Prompts, schema text, model responses, and the shared token are omitted from application logs. Cloudflare receives schema names, table names, column definitions, selected SQL, conversation history, and the user's instruction when the assistant is used.

Viewer contexts are bound to user, server, and database IDs in the Laravel session. Up to 20 contexts are retained per session. Permission revocation, database deletion, server suspension, logout, iframe reload, and bridge disposal all fail closed.

## Routes

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/database-viewer/servers/{server}/databases/{database}` | authorized viewer page |
| POST | `/database-viewer/servers/{server}/databases/{database}/query` | metadata and diagnostic broker |
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

The packager uses an immutable runtime allowlist and fixed timestamps. It creates `dist/database-viewer-0.3.0.zip` and a SHA-256 sidecar. Tests, plans, caches, tools, local configuration, and dependencies are excluded.

## Manual check

Install the archive in a disposable compatible Panel with a MariaDB database. Open **Databases → Open Database Viewer** as an owner and as a subuser with `database.read`. Studio should open its native schema browser and editor, and browser network traffic should show the single metadata transaction. In the query editor press Ctrl+B (Command+B on macOS), ask for a SQL query, and accept the generated SQL. Verify `SELECT 1` succeeds. Verify a row query, write, altered metadata statement, wrong channel, missing CSRF token, revoked permission, another server's database ID, and an invalid AI token are denied.

The automated suite verifies policy, SQL replacement, limits, serialization, message transport, cleanup, and Panel integration. A successful test run does not by itself prove production network reachability or the selected database user's metadata grants. See [verification.md](verification.md) for the recorded release evidence and [source-inspection.md](source-inspection.md) for Panel and Studio integration points.
