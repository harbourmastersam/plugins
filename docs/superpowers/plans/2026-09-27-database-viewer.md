# Database Viewer MVP implementation plan

**Goal:** Implement the user's supplied SELECT 1-only plugin specification using public release sources, without production access.

**Architecture:** A supported DatabaseResource table action opens a plugin-owned session-authenticated Blade page. The page binds one iframe to a random session context. A separately authorized POST endpoint invokes an injectable MariaDB executor using the selected Database model's encrypted credential cast. No core or Studio changes.

**Baseline:** pelican/panel v1.0.0-beta38 (e84a4afd4), PHP ^8.3 || ^8.4 || ^8.5, locked Laravel 13.25.0, Filament 5.7.6, Livewire 4.4.0. Official examples: pelican/plugins 9f845860. User spec: updated pasted request dated 2026-09-27.

## Constraints and decisions

- Scope: SELECT 1, optional surrounding whitespace and one semicolon; no arbitrary SQL or transactions.
- Use User::canAccessTenant and SubuserPermission::DatabaseRead against the actual server, matching DatabasePolicy without relying on ambient Filament tenant state.
- Use Server::databases() for lookup; never serialize a Database model into the view.
- Plugin metadata uses panel_version, not api_version (not consumed by this release).
- Standard web/auth/auth.session and Pelican 2FA middleware protect both page and broker; retain CSRF.
- Prefer a normal Blade page linked from the server resource to avoid credential-bearing Livewire state and unnecessary reactive lifetime complexity.
- Bound session contexts to 20 viewers; this is memory containment, not the deferred timed-session feature.
- MariaDB-specific session statement timeout; never use DatabaseHost::buildConnection because it authenticates as the host management user.
- Studio schema initialization requests must get controlled unsupported errors. Do not fake schema results to bypass the SQL restriction.

## Tasks

- [x] Add failing PHP and Node tests for authorization, contexts, result conversion, broker errors, origin/window/channel validation and bridge cleanup.
- [x] Implement metadata/config, authorization/context services, controller/routes, injected executor, action and Blade page.
- [x] Run tests against the selected release with PHP in WSL; test the actual Laravel router and CSRF middleware, and PDO configuration using doubles.
- [x] Verify plugin discovery/action integration; inspect Studio initialization constraints; review security and fix findings.
- [x] Document installation, exact APIs, manual verification, limitations and next task. Produce a ZIP from an explicit runtime file allowlist and verify its contents.

## Execution ledger

- Initial Node and PHP tests failed on missing bridge/provider, then passed after implementation.
- Tests explicitly load plugin provider because Panel skips plugins in testing; refreshed route lookup maps after late provider registration.
- Ruling: match Panel's DatabaseTruncation fixture strategy; transaction-backed fixtures are rolled back by Panel's HTTP exception handler, and upstream rollback migrations are not SQLite-safe. Temporary SQLite file removed at process shutdown.
- Tests flush Laravel Context between simulated HTTP requests, matching real request-scoped permission caching; permission revocation now checked correctly.
- Local PHP/Composer installed in WSL; beta38 dev dependencies installed in `/home/key/database-viewer-dev/panel`, separate from the source checkout and production.
- Final automated results: PHP 36 tests / 103 assertions; Node 14 tests; Pint clean. Normal plugin installer and all three routes verified in the disposable Panel.
- Ruling: do not bypass Studio's schema gate by fake results, extra SQL, or Studio changes. Existing SchemaProvider prevents the editor opening on rejected schema transactions. Deliver transport artifact and document incomplete end-to-end UI criterion.
- Requested reviewer failed due to usage allowance; performed local source/API/security review, with no claim of independent review.

## Authorized Studio follow-up

The user confirmed the schema-startup error and explicitly authorized the Studio change. A bounded `mode=probe` route now renders a focused SELECT 1 screen without constructing the full Studio driver. It sends a single query on click, validates replies through unchanged EmbedQueryable, closes listeners on completion/unmount and supports timeout/retry. Plugin 0.1.1 opts into that mode. Tests: 232 Studio tests (16 suites), 36 PHP tests / 104 assertions and 14 parent bridge tests passing. Studio typecheck and production Next.js build passed. Deployment remains separate.

## Review focus

- Permissions revoked after rendering must deny the next broker request.
- Switching database IDs, users, or channels must fail before connecting.
- Pending requests after iframe navigation/disposal must never receive a stale response.
- Failures must not report raw exception text or credentials, even with debug enabled.
- Unsupported Studio startup requests must settle with errors and preserve the SQL restriction.
