# Database Viewer 0.3.0 verification

Recorded on 2026-09-28 against Pelican Panel `1.0.0-beta38` and the matching GreyHarbour Studio source.

## Automated results

- Plugin PHP suite: **78 tests, 299 assertions**, PHP 8.4.26 and PHPUnit 12.5.33.
- Browser bridge suite: **25 tests passed** with Node's built-in test runner.
- Studio suite: **279 tests passed across 20 suites**.
- Studio TypeScript typecheck and ESLint: passed.
- Laravel Pint: every PHP file added or changed for 0.3.0 passed. A repository-wide check still reports pre-existing line-ending/style findings in unchanged files.
- Packaging unit suite: **2 tests passed**.
- Cloudflare OpenNext production build and Wrangler dry-run deployment: passed.

Tests cover the managed AI message transport, fixed-model provider selection, strict request and response envelopes, authentication, request and response size limits, redirect refusal, generic errors, route isolation, endpoint selection, and the separate 25-second AI and 8-second database request limits. Existing schema bootstrap, authorization, session binding, serialization, message identity, lifecycle, and packaging coverage also remains green.

## Production Worker check

Worker version `e3c6743f-0e99-4713-8305-c9001a5abf98` was deployed to `studio.greyharbour.net`. Live checks returned HTTP 200 for an iframe navigation with `frame-ancestors https://panel.greyharbour.net`, HTTP 403 for top-level navigation, HTTP 401 for an unauthenticated AI request, and HTTP 200 with the expected response shape for an authenticated AI request.

## Installation check

The final ZIP was extracted into a disposable local beta38 Panel. The supported install command completed successfully, caches were cleared, and the route check found the viewer GET, database broker POST, AI broker POST, and allowlisted asset GET routes.

```sh
php artisan p:plugin:install database-viewer
php artisan optimize:clear
php artisan route:list --name=database-viewer --except-vendor
```

## Limits

The automated executor tests use PDO and connector doubles, and the disposable Panel uses SQLite for its application data. The production Panel must set `DATABASE_VIEWER_AI_TOKEN` to the same secret stored in the Studio Worker before importing this archive.

The database broker provides schema discovery and `SELECT 1`. The AI broker sends the current schema context and prompt through Pelican to the protected Studio Worker, which uses the fixed `@cf/meta/llama-3.3-70b-instruct-fp8-fast` Workers AI model. General row queries, writes, DDL, arbitrary transactions, exports, and dumps remain outside this release.
