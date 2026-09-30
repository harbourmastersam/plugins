# Database Viewer 0.5.1 verification

Recorded on 2026-10-01 against Pelican Panel `1.0.0-beta38` and the matching GreyHarbour Studio source.

## Automated results

- Plugin PHP suite: **197 tests, 629 assertions passed** with PHP 8.4.26 and PHPUnit 12.5.33.
- Browser bridge and viewer suite: **44 tests passed** with Node's built-in test runner.
- Packaging unit suite: **3 tests passed**.
- Deterministic archive creation was repeated with identical SHA-256 output. ZIP ordering, fixed timestamps, Unix regular-file modes, CRC validation, runtime allowlist contents, sidecar content, and secret exclusion passed.

Coverage includes current owner/subuser authorization, Full and Read-only SQL policy, fail-closed SQL inspection, managed-batch atomicity, schema bootstrap isolation, hashed viewer sessions, Extend and Close semantics, bounded pruning, and deterministic packaging. Parent-page coverage now also proves the compact flex toolbar, quiet normal status, authoritative two-minute warning, one warning per expiry, dismissal and focus restoration, shared manual/dialog Extend behavior, immediate local expiry, Studio interaction blocking, pending-request abortion, Start new session and Back navigation, exact terminal server-code handling, non-terminal transaction rejection, and visibility/focus/pageshow reevaluation after background suspension.

## Installation check

The archive is intended for Pelican's plugin update flow. After import, run:

```sh
php artisan p:plugin:install database-viewer
php artisan migrate --force
php artisan optimize:clear
php artisan route:list --name=database-viewer --except-vendor
```

Restart long-lived PHP-FPM, Octane, Horizon, and queue worker processes. Confirm the viewer, query, AI, Extend, Close, and allowlisted asset routes. Reload existing viewer tabs so the 0.5.1 parent UI and lifecycle code replace cached 0.5.0 assets.

## Existing Studio and Worker evidence

The Studio and Workers AI integration was not changed by this Panel-only release. The preceding Studio/Worker validation recorded 279 Studio tests across 20 suites, TypeScript and ESLint passes, an OpenNext production build, and a Wrangler dry run. Worker version `e3c6743f-0e99-4713-8305-c9001a5abf98` returned the expected frame, top-level navigation, unauthenticated AI, and authenticated AI responses at that time.

That historical evidence does not claim that the 0.5.1 Panel archive has been deployed or exercised against live MariaDB.

## Manual MariaDB checks still required

Use a disposable database with transactional tables and verify:

1. a Full user can submit an `INSERT`/`UPDATE`/`DELETE` batch and a failure rolls back all earlier writes;
2. Studio DDL transaction envelopes receive `TRANSACTION_NOT_ATOMIC` without connecting, while individual Full DDL still executes;
3. a Read-only user can run `SELECT`, `SHOW`, and schema discovery but cannot write or submit a general batch;
4. aggregate result overflow rolls back the batch and returns no partial array;
5. removing update permission changes the next query to Read-only, and removing read/tenant access blocks SQL, AI, and Extend;
6. Extend uses server time, does not stack unused time, and stops at the two-hour maximum;
7. Close succeeds after permission loss, expires/revokes immediately, and does not affect another viewer;
8. the 21st active viewer is rejected and `database-viewer:prune-sessions` reports count-only cleanup;
9. safe MariaDB diagnostics appear without SQL, credentials, DSNs, paths, or control characters.

Also verify the compact toolbar at desktop and narrow widths, the warning dialog at two minutes, Escape and Not now dismissal, warning recurrence after Extend, immediate expiry after returning from a background tab, blocked Studio interaction, and both terminal navigation actions.

The executor tests use PDO and connector doubles, and the Panel test database is SQLite. Production behavior still depends on Panel-to-MariaDB reachability, the selected MariaDB user's grants, and transactional storage engines. `DATABASE_VIEWER_AI_TOKEN` must match the Studio Worker secret for the assistant to work.
