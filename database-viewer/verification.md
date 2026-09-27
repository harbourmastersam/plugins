# Verification and delivery inventory

Recorded 2026-09-27. The matching Studio Worker is deployed; no real production database query is claimed here.

## Results

- PHP 8.4.26 / PHPUnit 12.5.33: **37 tests, 107 assertions, all passing**, no PHPUnit notices. The URL regression test renders the iframe under `arg_separator.output=&amp;` and verifies that `mode=probe` is still emitted correctly.
- Matching Studio change: **232 Jest tests passing across all 16 suites** (82 in the focused security/probe suites), including probe rendering, actual iframe transport validation, timeouts/retry and embed route selection. TypeScript typecheck and the production Next.js build passed.
- Node 26.8.1 built-in test runner: **16 tests, all passing**. The 0.1.3 unit and viewer integration regressions verify that the browser adds `mode=probe` when a hot-loaded controller omits it.
- Laravel Pint: the PHP files changed in 0.1.2 are clean (`--test --quiet`); 0.1.3 changes no PHP files.
- Generated baseline scaffold with beta38's real `p:plugin:make` command in a disposable local Panel copy.
- Installed final plugin sources using `php artisan p:plugin:install database-viewer` in that copy: **installed and enabled**.
- `php artisan route:list --name=database-viewer --except-vendor`: all three expected GET/assets, GET/viewer and POST/query routes registered through the normal plugin loader.
- Real Filament table construction test confirms the plugin action exists, its URL/visibility work, and core View/Delete actions remain.
- No real MariaDB server is required or used by automated tests. The executor is tested with PDO/MySqlConnector doubles; HTTP tests use real Panel models, policies and SQLite fixtures.
- Source and resource packaging uses an explicit allowlist, fixed timestamps, archive integrity check and a SHA-256 sidecar.
- Panel core source remains unchanged. The user subsequently authorized Studio changes: the matching Studio branch `codex/embed-select-one-probe` now implements `mode=probe` without changing the hardened transport.
- An independent reviewer could not run because its usage allowance was exhausted; review was performed locally instead. No independent-review pass is claimed.

## Limits of these results

This proves the authorization, fixed-query execution wiring, message filtering, response conversion and plugin integration in tests. The matching Studio probe tests render the returned value in a real React DOM without schema discovery. It does **not** prove a real private-network MariaDB connection, production compatibility or a deployed browser-to-MariaDB round trip. Deploy both updates and run the manual check. Normal Studio mode still blocks on schema errors; the iframe must include `mode=probe`.

The MariaDB three-second statement timeout is sent as a PDO connection initialization command. Tests verify configuration but do not exercise a real server timeout or socket stall. No MySQL compatibility claim is made. Timed viewer expiry, explicit revocation controls and general SQL remain outside this release.

## All added plugin files

```text
database-viewer/
├── .gitignore
├── LICENSE
├── README.md
├── source-inspection.md
├── verification.md
├── plugin.json
├── phpunit.xml
├── config/
│   └── database-viewer.php
├── routes/
│   └── web.php
├── src/
│   ├── DatabaseViewerPlugin.php
│   ├── Providers/
│   │   └── DatabaseViewerPluginProvider.php
│   ├── Http/
│   │   └── ViewerController.php
│   └── Services/
│       ├── MariaDbExecutor.php
│       ├── QueryExecutor.php
│       ├── StudioOrigin.php
│       ├── ViewerAccess.php
│       └── ViewerContext.php
├── resources/
│   ├── js/
│   │   ├── bridge.mjs
│   │   └── viewer.mjs
│   └── views/
│       └── viewer.blade.php
└── tests/
    ├── bootstrap.php
    ├── bridge.test.mjs
    ├── viewer.test.mjs
    ├── StudioOriginTest.php
    └── ViewerTest.php
```

Other added files in the plugins workspace:

- `docs/superpowers/plans/2026-09-27-database-viewer.md`: implementation decisions and verification ledger.
- `tools/package-database-viewer.py`: deterministic allowlist packager.
- `dist/database-viewer-0.1.3.zip`: current runtime/documentation release archive.
- `dist/database-viewer-0.1.3.zip.sha256`: current archive checksum.
- The 0.1.0 through 0.1.2 archives/checksums are retained as historical artifacts; install 0.1.3 with the updated Studio build.

Existing file changed: root `README.md` adds the Database Viewer entry. No existing plugin files were changed. Plugin `.gitignore`, tests, phpunit.xml, plans, tools and test caches are excluded from the ZIP. The copied LICENSE is the existing plugins repository's GPLv3 license.
