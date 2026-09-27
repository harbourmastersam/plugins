# Database Viewer 0.2.0 verification

Recorded on 2026-09-27 against Pelican Panel `1.0.0-beta38` and the matching GreyHarbour Studio source.

## Automated results

- Plugin PHP suite: **74 tests, 266 assertions**, PHP 8.4.26 and PHPUnit 12.5.33, with `--fail-on-notice`.
- Browser bridge suite: **21 tests passed** with Node's built-in test runner.
- Studio suite: **251 tests passed across 17 suites**.
- Studio TypeScript typecheck: passed.
- Studio ESLint: passed.
- Studio production Next.js build: passed. The build emitted the existing workspace-root and Shiki-instance warnings; neither failed the build.
- Laravel Pint: every PHP file added or changed for 0.2.0 passed. A repository-wide plugin check also reports pre-existing line-ending/style findings in unchanged 0.1.x files.
- Packaging unit suite: passed. It checks exact allowlisted paths, uniqueness, fixed timestamps, regular-file modes, CRC integrity, repeatable bytes, excluded development paths, and the SHA-256 sidecar.

Tests cover exact metadata-operation ordering and shape, database quoting, malformed and oversized requests, raw and serialized size limits, fixed server-owned SQL, one-connection execution, typed serialization, unsafe result rejection, authorization and session binding, response status policy, iframe scoping, browser message identity, stable-window reload races with colliding request IDs, disposal cleanup, and generic error handling.

## Installation check

The final ZIP was extracted into a disposable local beta38 Panel. After recreating its SQLite development database, the supported command completed with **Plugin installed and enabled**:

```sh
php artisan p:plugin:install database-viewer
php artisan optimize:clear
php artisan route:list --name=database-viewer --except-vendor
```

The route check found the expected viewer GET, broker POST, and allowlisted asset GET routes. The PHPUnit integration suite constructs the real Filament database table and verifies that the plugin action is visible to authorized users while core actions remain present.

## Limits

The automated executor tests use PDO and connector doubles, and the disposable Panel uses SQLite for its application data. No real MariaDB connection or production browser-to-database round trip is claimed by this record. Production verification still depends on Panel network reachability, the selected database user's metadata grants, and importing this exact plugin archive after the matching Studio deployment.

The broker provides schema discovery and `SELECT 1` only. General row queries, writes, DDL, arbitrary transactions, exports, and dumps remain outside this release.
