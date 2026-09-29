# Database Viewer 0.4.0 verification

Recorded on 2026-09-30 against Pelican Panel `1.0.0-beta38` and the matching GreyHarbour Studio source.

## Automated results

- Plugin PHP suite: **121 tests, 440 assertions**, PHP 8.4.26 and PHPUnit 12.5.33.
- Browser bridge and viewer suite: **27 tests passed** with Node's built-in test runner.
- Packaging unit suite: **3 tests passed**.
- Deterministic archive creation, ZIP CRC validation, runtime allowlist inspection, and SHA-256 sidecar verification passed.

Coverage includes owner and subuser access derivation, permission revocation, fixed schema replacement, full and read-only SQL policy, MariaDB read-only transaction enforcement, sequential batches, command statistics, insert IDs, incremental result serialization, request and response limits, safe database diagnostics, audit metadata, AI transport, message identity, iframe reload isolation, and package contents.

## Existing Studio and Worker evidence

The Studio and Workers AI integration did not change in 0.4.0. The preceding 0.3.0 release recorded 279 Studio tests across 20 suites, TypeScript and ESLint passes, an OpenNext production build, and a Wrangler dry run. Worker version `e3c6743f-0e99-4713-8305-c9001a5abf98` returned the expected frame, top-level navigation, unauthenticated AI, and authenticated AI responses at that time.

This historical evidence does not claim that the 0.4.0 Panel archive has been deployed or manually exercised against production MariaDB.

## Installation check

The archive is intended for Pelican's plugin update flow. After import, run:

```sh
php artisan p:plugin:install database-viewer
php artisan optimize:clear
php artisan route:list --name=database-viewer --except-vendor
```

Restart long-lived PHP-FPM, Octane, Horizon, and queue worker processes. Verify the viewer GET, database broker POST, AI broker POST, and allowlisted asset GET routes before enabling general use.

## Manual MariaDB checks still required

Use a disposable database and verify:

1. an owner can create, insert, select, update, delete, and drop a table;
2. a read+update subuser receives the same full access;
3. a read-only subuser can run `SELECT`, `SHOW`, and schema discovery but cannot write;
4. explicit `START TRANSACTION` and `ROLLBACK` behave as submitted;
5. a batch stops at its first error without claiming rollback of earlier auto-committed effects;
6. permission revocation takes effect on the next request;
7. safe MariaDB diagnostics appear without SQL, credentials, DSNs, paths, or control characters.

The automated executor tests use PDO and connector doubles, and the Panel test database is SQLite. Production behavior still depends on Panel-to-MariaDB network reachability and the privileges of the selected database's MariaDB account. `DATABASE_VIEWER_AI_TOKEN` must match the Studio Worker secret for the assistant to work.
