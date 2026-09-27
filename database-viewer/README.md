# Database Viewer 0.1.1

Plugin-only transport MVP for Pelican and GreyHarbour's hardened Outerbase Studio fork. **Only `SELECT 1` can execute.** No database credentials enter the viewer page, JavaScript, iframe URL, or Studio.

**Requires the updated Studio probe build:** version 0.1.1 opens `/embed/mysql?channel=...&mode=probe`. The separately authorized Studio change displays a focused **Run SELECT 1** screen without constructing the schema-loading driver. It uses the existing hardened iframe transport, sends a single query only when clicked, and displays the returned result. Normal Studio mode still requires schema discovery and cannot work with this restricted broker. No schema results are fabricated and no additional SQL is allowed.

## Baseline and supported APIs

- Panel: [pelican/panel v1.0.0-beta38](https://github.com/pelican/panel/tree/v1.0.0-beta38), commit `e84a4afd4`; latest published release checked on 2026-09-27. No production version was supplied.
- Required PHP: `^8.3 || ^8.4 || ^8.5`; actual test runtime PHP 8.4.26. The release's development dependencies require PHP 8.4 for PHPUnit 12/Pest 4.
- Locked Laravel 13.25.0, Filament 5.7.6, Livewire 4.4.0.
- Official examples: [pelican/plugins](https://github.com/pelican/plugins), commit `9f84586016945c50e0663e2ebeb02af0992243be` (Tickets and Subdomains).
- Scaffold generated locally with `php artisan p:plugin:make`; its `src`, provider, config and metadata layout is retained. Version changed to `0.1.0`; distributable metadata omits generated enabled-state metadata so installation is required.
- `panel_version: "1.0.0-beta38"` is deliberately exact. Confirm production compatibility before deployment. No `api_version` field: this tag's generator and loader do not consume it, although some official example manifests contain it.
- Panels: `admin`, `app` (server list), `server` (client tenant area). Server tenants use `uuid_short`. This plugin targets `server`.
- `DatabaseResource::modifyTable` and Filament `Table::pushRecordActions` append the action and preserve core actions. A standalone authenticated Blade page opens in a new tab from the server's Databases table. No core resource override, reactive state, or unsupported action injection is used.

See [source-inspection.md](source-inspection.md) for the exact model, policy and service evidence.

## Installation (a separate deployment step)

These commands are instructions only; development did not access production.

1. Confirm that Panel is beta38, has PHP `pdo_mysql`, and the Panel machine can reach the selected database host over your existing private network. This plugin targets MariaDB, including its `max_statement_time` setting.
2. Extract the release archive so the final path is `/var/www/pelican/plugins/database-viewer/plugin.json`. The ZIP includes the `database-viewer/` directory. Keep its ID and directory name identical. Use the normal Panel filesystem owner.
3. From the Panel installation directory, run the supported installer:

   ```sh
   php artisan p:plugin:install database-viewer
   php artisan optimize:clear
   ```

4. Log in as a permitted owner/admin/subuser, open a server's **Databases** table, and select **Open Database Viewer**. No plugin migrations, Composer dependencies, npm installation or asset build are needed.
5. Studio must be built to allow the exact parent origin `https://panel.greyharbour.net`. Its production build rejects localhost parents. Use a separately configured development Studio build for a different development Panel origin.

Deploy the matching Studio probe-mode build before installing this plugin version. An older Studio build ignores `mode=probe` and will still show its schema-startup connection error. Updating either repository locally does not deploy it.

## Studio configuration

Optional Panel environment setting:

```dotenv
DATABASE_VIEWER_STUDIO_ORIGIN=https://studio.greyharbour.net
```

The plugin loads this via `config/database-viewer.php`. Run `php artisan optimize:clear` after changing it. It must be one lowercase, exact HTTPS origin, optionally with a port. No path, trailing slash, query, fragment, credentials, wildcard, or origin list is accepted. Port 443 is normalized away to match browser `event.origin`. Invalid configuration fails closed when opening the viewer. There are no credential settings or plugin admin-settings form.

The iframe URL is exactly `<origin>/embed/mysql?channel=<random-channel>&mode=probe`; it contains no tokens or connection details. The sandbox grants only `allow-scripts allow-same-origin`, required for Studio scripts and a non-opaque message origin. It grants no top navigation, popups, forms, or downloads. Parent and iframe are different origins.

## Architecture and authorization

```text
Studio iframe → validated postMessage → Pelican parent fetch (session + CSRF)
             → independently authorized broker → selected MariaDB database
             ← Studio result envelope ← generic JSON result/error
```

Both GET and POST resolve the server by `uuid_short`, call `User::canAccessTenant($server)`, then `User::can(SubuserPermission::DatabaseRead, $server)`, and retrieve the database through `Server::databases()`. This is the same permission check used by `DatabasePolicy::view/viewAny`, with an explicit server rather than ambient Filament tenant state. Owners and admins follow Pelican's normal authorization behavior. Suspended/conflicting servers are denied. `DatabaseViewPassword` is not required and no password is exposed.

Both routes use normal `web`, `auth`, `auth.session`, and Pelican's `RequireTwoFactorAuthentication` middleware. Laravel CSRF is retained; no exemptions are added. The POST is limited to 30 requests/minute by Laravel's normal authenticated-user limiter.

Every page creates 32 cryptographically random bytes, encoded as 43 Base64URL characters. The Laravel session stores the association with user/server/database IDs, and the POST verifies it again after authorization. Up to 20 viewer contexts are retained per session; opening the 21st invalidates the oldest context. Channels are message binding, not credentials. There is no separate viewer expiry clock or database session table. Normal Panel session logout/expiry still applies.

The bridge checks exact origin, iframe `contentWindow`, channel, safe integer ID, operation and field types. It ignores malformed messages, rejects unsupported SQL and transactions explicitly, avoids duplicate in-flight IDs, and drops replies after disposal or iframe reload. Browser requests abort after eight seconds. Replies use only the exact configured Studio origin and allowlisted result fields.

## Routes

| Method | Path | Name |
| --- | --- | --- |
| GET | `/database-viewer/servers/{server}/databases/{database}` | `database-viewer.show` |
| POST | `/database-viewer/servers/{server}/databases/{database}/query` | `database-viewer.query` |
| GET | `/database-viewer/assets/{asset}` | `database-viewer.asset` |

`server` is `uuid_short`; `database` is the numeric ID looked up under that server. Assets are restricted to `bridge.mjs` and `viewer.mjs`. POST JSON is `{ "channel": "...", "statement": "SELECT 1" }`. Request IDs stay in the browser bridge, which echoes them to Studio. No ID is needed to authorize or execute SQL on the backend.

## Execution and result

`Database::$casts['password'] = 'encrypted'` supplies the decrypted password at execution time. `Database::host` supplies `host` and `port`; the selected Database supplies schema name, username and password. No credentials are copied or persisted by the plugin. The host's administrative username/password are never used. In particular, `DatabaseHost::buildConnection()` would use those management credentials, so the plugin instead uses Laravel's `MySqlConnector` with the selected database credentials and a request-local PDO.

The executor has no SQL parameter. After an exact allowlist check, it always sends the literal `SELECT 1`. It permits whitespace, case changes and one trailing semicolon in the incoming test statement, but never executes that incoming string. PDO multi-statements and persistence are disabled; connection timeout is three seconds. MariaDB gets a session-local three-second `max_statement_time`. Laravel also initializes the connection charset. These connection settings do not alter stored data or schema. PDO/statement references are released in `finally`. A browser timeout does not cancel PHP or guarantee a network-level read deadline; infrastructure request limits remain relevant.

The real row is checked before converting the test query result. The broker returns `{ "data": <result> }`, where the result is:

```json
{
  "headers": [{ "name": "1", "displayName": "1", "originalType": "INT", "type": 2 }],
  "rows": [{ "1": 1 }],
  "stat": { "rowsAffected": 0, "rowsRead": 1, "rowsWritten": null, "queryDurationMs": 1 }
}
```

`queryDurationMs` is measured elapsed connection/query time, not a constant. The parent sends `{ type: "query", id, channel, data }`. On error it sends `{ type, id, channel, error: "Database query failed." }` with no `data`. Unsupported queries use `Query not permitted in MVP mode.`; transactions use `Transactions are not supported in MVP mode.` and echo the transaction type.

Logs contain user/server/database IDs, operation, outcome and duration. PDO exception text, SQL, credentials, cookies, authorization headers and channels are not logged by this plugin. Query exceptions are caught without passing them to the exception reporter. No model is serialized into the HTML or API response.

## Tests and local development

The test suite uses PHPUnit from the release's Pest/PHPUnit toolchain, real Panel models/policies/router/migrations and a disposable local SQLite database. Plugin loading is disabled by Panel in testing, so the test harness explicitly registers the provider and refreshes route lookups. Like Panel's integration tests, it truncates fixtures instead of transaction-wrapping them: Panel's exception handler rolls transactions back on denied requests. Each simulated HTTP request clears Laravel Context to match the request-scoped permission cache.

With a local beta38 Panel and its Composer dev dependencies installed:

```sh
PELICAN_PATH=/absolute/path/to/panel /absolute/path/to/panel/vendor/bin/phpunit -c /absolute/path/to/plugins/database-viewer/phpunit.xml
node --test database-viewer/tests/bridge.test.mjs
```

Run Node from the plugins repository root. No production MariaDB instance is used. Tests cover owner/subuser/admin access, revoked permissions, foreign/deleted database IDs, suspended servers, session binding, CSRF (with Laravel's unit-test bypass explicitly disabled), SQL rejection, fake executor success, PDO connection configuration/result validation, safe errors, origin configuration, the supported table action, and browser message checks/cleanup. See [verification.md](verification.md) for the recorded run and limitations.

Build the allowlisted release archive from the plugins repository root:

```sh
python3 tools/package-database-viewer.py
```

Output: `dist/database-viewer-0.1.1.zip` plus SHA-256 checksum. Tests, caches, tools, development plans, environment files and dependencies are excluded.

## Manual verification

1. On an isolated compatible development Panel, install the ZIP, create a test server/database, and grant a subuser only `database.read`. Owner and permitted subuser should see the action and open the page; an unrelated user or a subuser without it should receive 403.
2. Inspect the iframe URL/HTML/network: only the channel travels to Studio. The parent CSRF token stays on the Panel origin. No database password/username/host should appear in plugin responses or HTML.
3. The updated Studio should show **Run SELECT 1** with no startup database requests. Click it and verify that the outgoing operation is `query`, the statement is `SELECT 1`, and the result table displays column `1`, value `1`. If schema transactions still appear, check the deployed Studio version and iframe `mode=probe` parameter.
4. To test the broker separately when troubleshooting, in the **parent page** developer console run:

   ```js
   const frame = document.getElementById('database-viewer');
   const response = await fetch(frame.dataset.queryUrl, {
     method: 'POST', credentials: 'same-origin',
     headers: { 'Content-Type': 'application/json', Accept: 'application/json',
       'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
     body: JSON.stringify({ channel: frame.dataset.channel, statement: 'SELECT 1' })
   });
   console.log(response.status, await response.json());
   ```

   This manually verifies Panel → MariaDB only; it does not prove the Studio UI flow. Expect a 200 and the result above when using a reachable **development** MariaDB database.
5. Repeat with `SELECT 2` (422), a wrong channel (403), another server's database ID (404), and no CSRF token (419). Revoke the subuser permission after opening the viewer and retry (403). Delete the database and retry (404). No denied request should reach MariaDB.
6. Wrong-origin/window/channel tests can be repeated using the automated Node and Studio Jest suites; do not disable production origin checks to test. Repeat after permission revocation, logout and reload.

## Troubleshooting and uninstall

- Missing action: check installed/enabled plugin state, exact ID/path and beta38 compatibility, then clear caches.
- Configuration failure: use one exact HTTPS origin as shown above.
- Blank/blocked iframe: check Studio's compiled parent-origin setting, `frame-ancestors` CSP, reverse-proxy `frame-src`, HTTPS, and browser console. A local Panel cannot frame the production Studio build unless its exact origin is allowed by Studio.
- Studio connection-error screen with unsupported transaction: an older Studio deployment or a missing `mode=probe` parameter is still running normal schema initialization.
- 401/419: log in again or reload the page for fresh session/CSRF state. 403: check permission, channel context and server status. After more than 20 viewers, reload an evicted viewer.
- 503 query failure: confirm the selected database user's grants and allowed client address, private-network reachability, MariaDB version and PDO driver. Plugin logs deliberately omit low-level connection secrets. Oracle MySQL's different timeout setting is not supported by this MVP.
- Uninstall through the Panel Plugins UI, or run `php artisan p:plugin:uninstall database-viewer --delete`, then `php artisan optimize:clear`. Restart long-lived Panel workers if used. No plugin tables or copied credentials exist to clean up; normal session records disappear with their Panel session lifecycle.

## Next task

Deploy the matching Studio probe build and this plugin, then verify the real end-to-end display. Next add server-side temporary viewer sessions with approximately 15-minute expiry, Extend/Close controls, revocation and concurrency tests. Broader SQL support needs its own authorization/limits/error-handling design after that. Nothing in this release provides arbitrary queries, writes, exports, dumps, custom database users, Cloudflare integration, auto-updates or direct Studio-to-MariaDB connectivity.
