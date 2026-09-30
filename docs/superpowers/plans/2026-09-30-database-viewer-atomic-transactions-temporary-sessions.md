# Database Viewer Atomic Transactions and Temporary Sessions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make full-access Studio DML transactions genuinely atomic and replace untimed viewer contexts with 15-minute, extendable, revocable server-side viewer sessions.

**Architecture:** A fail-closed SQL inspector admits only transaction-compatible statement roots before `MariaDbExecutor` opens one PDO transaction. A plugin-owned viewer-session table stores hashed channel bindings and authoritative expiry, while dedicated lifecycle services and endpoints reauthorize current Pelican access, manage Extend/Close, and drive parent-page controls.

**Tech Stack:** PHP 8.3+, Laravel/Pelican Panel beta38, Eloquent, PDO MySQL/MariaDB, Blade, JavaScript ES modules, PHPUnit, Node test runner, Python packaging tests.

**Spec:** `docs/superpowers/specs/2026-09-30-database-viewer-atomic-transactions-temporary-sessions-design.md`

## Global Constraints

- Keep `database.read` as read-only and `database.read` plus `database.update` as full SQL; add no permission.
- Use a 15-minute initial expiry, explicit Extend to `now + 15 minutes`, and a two-hour absolute maximum from creation.
- Store only the SHA-256 hash of each 256-bit random channel; never persist the raw channel, SQL, AI prompts/responses, credentials, passwords, or access mode.
- Activity updates `last_activity_at` only and never changes `expires_at`.
- Allow at most 20 active viewers per user and reject the 21st; serialize concurrent creation attempts by locking the Pelican user row.
- SQL, AI, and Extend perform full current Pelican authorization; Close verifies owned viewer identity/context but remains available after `database.read` is removed.
- Validate the complete encoded `{data: results}` envelope against 5 MiB before managed-transaction commit; aggregate overflow and JSON encoding failure roll back.
- Include authoritative `serverNow` with initial/extended lifecycle timestamps; derive the cosmetic countdown from server duration and monotonic browser elapsed time.
- Trust only exact `SESSION_EXPIRED`, `SESSION_CLOSED`, and `TRANSACTION_NOT_ATOMIC` envelopes; only the two session codes dispose the viewer.
- Preserve exact Studio origin, iframe window, channel, document nonce, request ID, duplicate-inflight, CSRF, auth-session, two-factor, server/database relationship, timeout, response-limit, safe-error, SQL-free logging, and AI controls.
- Keep `PDO::MYSQL_ATTR_MULTI_STATEMENTS = false`, non-persistent connections, and the canonical six-query schema bootstrap.
- Do not modify Studio in this release.
- Produce Database Viewer 0.5.0 as a deterministic archive and SHA-256 sidecar without the local `database-viewer-ai-token.txt`.

## Review Focus

- A CTE, comment, executable comment, trailing token, or quoted keyword must not disguise DDL or transaction control; Task 1 pins fail-closed lexical behavior.
- A database/serialization/aggregate-response/commit failure must roll back all preceding transactional DML and return no partial array; Task 2 pins every failure position and exact byte boundary.
- Two simultaneous viewer creations must not both pass an active count of 19 and create a 21st session; Task 3 pins the user-row lock and limit query.
- Expiry, revocation, and permission loss must stop SQL/AI before either downstream service is called, while permission loss must not block Close; Tasks 4 and 5 pin ordering.
- Browser clock skew, duplicate clicks, iframe reloads, or lifecycle/policy errors must not extend authority, dispose a valid viewer after policy rejection, or revive a terminally disposed bridge; Task 6 pins authoritative responses and distinct code handling.

---

### Task 1: Managed transaction statement policy

**Files:**
- Create: `database-viewer/src/Services/SqlStatementInspector.php`
- Create: `database-viewer/src/Services/ManagedTransactionPolicy.php`
- Modify: `database-viewer/src/Services/GeneralSqlPolicy.php`
- Create: `database-viewer/tests/ManagedTransactionPolicyTest.php`
- Modify: `database-viewer/tests/GeneralSqlPolicyTest.php`

**Interfaces:**
- Produces `SqlStatementInspector::tokens(string $statement): ?array`, returning normalized token/depth pairs only for one well-formed statement.
- Produces `SqlStatementInspector::rootOperation(string $statement): ?string`, resolving the top-level operation after `WITH` and returning `null` on ambiguity.
- Produces `ManagedTransactionPolicy::allowsStatement(string $statement): bool` and `allowsBatch(array $statements): bool`; both require an allowed root and a safe complete token stream.
- `GeneralSqlPolicy` consumes the shared inspector without changing its existing public behavior.

- [ ] **Step 1: Add failing managed-policy tests.** Accept ordinary `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `REPLACE`, and safe `WITH` forms with whitespace, ordinary comments, quoted values, and one trailing semicolon. Reject `SELECT ... INTO OUTFILE`, `SELECT ... INTO DUMPFILE`, `SELECT ... FOR UPDATE`, `LOCK IN SHARE MODE`, unsafe operations hidden after `WITH`, DDL, database/view/trigger operations, administrative/maintenance commands, `LOAD`, grants, `SET`, locks, XA, `CALL`, dynamic execution, explicit transaction control, executable comments, malformed quoting/parentheses, multiple statements, empty batches, and a batch containing one rejected statement.
- [ ] **Step 2: Add regression tests around the extracted inspector.** Pin the existing read-only handling for `SHOW`, `DESCRIBE`, `EXPLAIN`, forbidden output/locking sequences, CTE writes, and quoted/commented keywords.
- [ ] **Step 3: Run `phpunit -c database-viewer/phpunit.xml --filter='ManagedTransactionPolicy|GeneralSqlPolicy'` and verify failures identify the missing inspector/policy.**
- [ ] **Step 4: Extract the current lexer into `SqlStatementInspector`, add top-level `WITH` root resolution and full-stream sequence checks, and inject it into both SQL policies.** Unknown or malformed syntax returns false; the managed root allowlist is exactly `SELECT`, `INSERT`, `UPDATE`, `DELETE`, and `REPLACE`, and an allowed root is rejected when any forbidden file-output, explicit-locking, transaction-control, implicit-commit, administrative, stored-program, or XA construct appears anywhere relevant in the statement.
- [ ] **Step 5: Re-run the focused policy tests and verify they pass.**
- [ ] **Step 6: Commit `SqlStatementInspector.php`, both policy files, and their tests with message `Reject non-atomic database viewer transactions`.**

### Task 2: Atomic MariaDB batch execution

**Files:**
- Create: `database-viewer/src/Services/BrokerResponseGuard.php`
- Modify: `database-viewer/src/Services/MariaDbExecutor.php`
- Create: `database-viewer/tests/BrokerResponseGuardTest.php`
- Modify: `database-viewer/tests/MariaDbExecutorTest.php`

**Interfaces:**
- Keeps `QueryExecutor::executeStatements(Database $database, array $statements, SqlAccessMode $mode): array`.
- `BrokerResponseGuard::encodeData(mixed $data): string` JSON-encodes the exact `{data: ...}` envelope with `JSON_THROW_ON_ERROR`, enforces `BrokerLimits::MAX_RESPONSE_BYTES`, and gives executor and controller one shared encoding/size rule.
- Full general batches begin one PDO transaction, construct and validate the complete encoded result envelope, commit once only after that check succeeds, and roll back an active transaction on any throwable.
- Fixed schema `executeBatch()` and single `executeStatement()` paths remain separate.

- [ ] **Step 1: Replace the existing non-atomic batch expectations with failing tests for two and three successful DML statements.** Assert one connection, one `beginTransaction()`, ordered preparation/execution/serialization, and a single commit after the final result.
- [ ] **Step 2: Add failing response-guard and executor tests for aggregate size.** Construct two results that each fit below 5 MiB but whose combined `{data:[A,B]}` envelope exceeds the limit; assert both may execute, validation fails before commit, rollback occurs, and no array returns. Pin exact complete-envelope limit as commit success, one byte over as rollback, and complete-envelope JSON encoding failure as rollback.
- [ ] **Step 3: Add failing tests for statement 2 failure, final-statement failure, serializer failure, and commit failure.** Assert rollback is attempted when `inTransaction()` is true, later statements are not executed, and the caller receives an exception rather than any result array.
- [ ] **Step 4: Add regression tests proving rollback is not retried when PDO reports no active transaction, a rollback failure does not replace the original database diagnostic, the connection remains request-local/releasable, schema bootstrap never begins a writable transaction, single Full DDL remains unchanged, and read-only execution still starts a read-only transaction and rolls back.**
- [ ] **Step 5: Run `phpunit -c database-viewer/phpunit.xml --filter='BrokerResponseGuard|MariaDbExecutor'` and verify the atomic/aggregate assertions fail against sequential autocommit behavior.**
- [ ] **Step 6: Implement the shared guard and wrap only full general batches in `beginTransaction()`/`commit()` with guarded rollback.** Build all result arrays, call `BrokerResponseGuard::encodeData($results)`, and commit only after it returns successfully; preserve `DatabaseStatementException` and safe generic failures.
- [ ] **Step 7: Re-run response-guard, executor, serializer, and controller response-boundary tests and verify they pass.**
- [ ] **Step 8: Commit the guard, executor, and tests with message `Execute Studio DML batches atomically`.**

### Task 3: Persistent viewer-session storage and lifecycle service

**Files:**
- Create: `database-viewer/database/migrations/001_create_database_viewer_sessions_table.php`
- Create: `database-viewer/src/Models/ViewerSession.php`
- Create: `database-viewer/src/ValueObjects/ViewerSessionHandle.php`
- Create: `database-viewer/src/Exceptions/ViewerSessionException.php`
- Create: `database-viewer/src/Services/ViewerSessionManager.php`
- Create: `database-viewer/tests/ViewerSessionManagerTest.php`
- Modify: `database-viewer/tests/ViewerTest.php` test application migration setup

**Interfaces:**
- `ViewerSessionHandle` exposes readonly `channel`, `expiresAt`, `maxExpiresAt`, and `serverNow` values captured from one authoritative clock reading.
- `ViewerSessionManager::create(User $user, Server $server, Database $database): ViewerSessionHandle` creates a hashed 15-minute session under the concurrency-safe 20-viewer limit.
- `validate(string $channel, int $userId, int $serverId, int $databaseId): ViewerSession` checks binding/state and updates only `last_activity_at`.
- `extend(string $channel, int $userId, int $serverId, int $databaseId): ViewerSessionHandle` locks and sets `min(now + 15 minutes, created_at + 2 hours)` and returns that expiry with the same operation's `serverNow`.
- `close(string $channel, int $userId, int $serverId, int $databaseId): ViewerSession` revokes idempotently without access-mode checks.
- `ViewerSessionException` exposes fixed HTTP status, optional application code, and safe message for mismatch, limit, expired, and closed outcomes.

- [ ] **Step 1: Add the plugin migration to the test migrator path and write a failing migration-shape test.** Assert all approved columns, `UNIQUE(channel_hash)`, the active-count composite index `(user_id, revoked_at, expires_at)` with equality predicates before the expiry range, separate expiry/revocation pruning indexes, and no raw token/content columns.
- [ ] **Step 2: Write failing controlled-clock tests for create.** Assert SHA-256-at-rest, distinct channels/rows, exact timestamps including returned `serverNow`, no stored access mode, no raw channel, active-viewer definition, explicit 21st rejection, and the user-row `lockForUpdate()` path used inside a Pelican DB transaction. Assert no pruning query runs between acquiring that lock and committing/rolling back creation.
- [ ] **Step 3: Write failing validation tests for correct binding, wrong user/server/database/channel, exact-expiry failure, revoked failure, and `last_activity_at` update without expiry change.**
- [ ] **Step 4: Write failing Extend tests for `now + 15 minutes`, returned authoritative `serverNow`, non-stacking behavior, two-hour cap, exact maximum, expired/closed rejection, and atomic row locking.**
- [ ] **Step 5: Write failing Close tests for immediate revocation, repeated successful Close, context mismatch, and isolation between two sessions.**
- [ ] **Step 6: Run `phpunit -c database-viewer/phpunit.xml --filter=ViewerSessionManagerTest` and verify the missing migration/service failures.**
- [ ] **Step 7: Implement the migration, Eloquent model with timestamps disabled/casts defined, immutable handle, fixed session exceptions, and manager methods.** Use `hash('sha256', $channel)`, `hash_equals` where comparing digests outside exact indexed lookup, one captured `now`, user-row locking only around active count plus insert, and row locking during Extend/Close.
- [ ] **Step 8: Re-run the session-manager tests and verify they pass.**
- [ ] **Step 9: Commit persistence and tests with message `Add temporary database viewer sessions`.**

### Task 4: Broker authorization and managed transaction routing

**Files:**
- Modify: `database-viewer/src/Http/ViewerController.php`
- Modify: `database-viewer/src/Services/ViewerContext.php` (remove after all consumers migrate)
- Modify: `database-viewer/tests/ViewerTest.php`

**Interfaces:**
- `ViewerController::show()` consumes `ViewerSessionManager::create()` and passes authoritative `expiresAt`, `maxExpiresAt`, `serverNow`, and lifecycle URLs to Blade.
- SQL and AI resolve current `ViewerAccess` first, then consume `ViewerSessionManager::validate()` before policy or downstream execution.
- General transaction envelopes consume `ManagedTransactionPolicy`; rejected valid batches return HTTP 422 with exactly `{error: "This operation cannot be executed atomically.", code: "TRANSACTION_NOT_ATOMIC"}`.
- Expired/closed broker requests return HTTP 410 with `SESSION_EXPIRED`/`SESSION_CLOSED`.
- Successful query responses use `BrokerResponseGuard::encodeData()` as the controller's final defense-in-depth encoding/size check.

- [ ] **Step 1: Replace Laravel-session context tests with failing persistent-session feature tests.** Assert opening creates a 15-minute row, produces distinct hashed identities, never renders credentials/raw stored hashes, enforces 20 active viewers, and uses no `database-viewer.contexts` session data.
- [ ] **Step 2: Add failing SQL and AI tests for valid identity, wrong binding/channel, expired and closed codes, and zero `QueryExecutor`/`AiBroker` calls after session rejection.** Freeze/advance time rather than sleeping.
- [ ] **Step 3: Add failing permission-change tests.** Removing update changes Full to Read-only on the next query; removing read or tenant access prevents SQL and AI; each accepted request updates activity without changing expiry.
- [ ] **Step 4: Add failing transaction-controller tests.** Accepted DML reaches `executeStatements`; explicit transaction control and representative implicit-commit/DDL statements return the exact safe HTTP 422 `TRANSACTION_NOT_ATOMIC` envelope before connecting; canonical schema bootstrap still reaches only `executeBatch`; individual Full DDL reaches `executeStatement`; read-only general batches remain denied. Issue a valid query after the rejected transaction and prove it succeeds under the same viewer session.
- [ ] **Step 5: Run `phpunit -c database-viewer/phpunit.xml --filter=ViewerTest` and verify failures show the old `ViewerContext` and unrestricted batch routing.**
- [ ] **Step 6: Inject `ViewerSessionManager`, `ManagedTransactionPolicy`, and `BrokerResponseGuard`; centralize safe session/policy error mapping and update show/SQL/AI paths in the required authorization order.** Use the shared guard for the final successful response, and delete `ViewerContext` after its final reference and test import are removed.
- [ ] **Step 7: Re-run `ViewerTest`, policy tests, and executor tests; verify they pass with no downstream call on prevalidation/session failures.**
- [ ] **Step 8: Commit controller integration with message `Authorize database viewer sessions per request`.**

### Task 5: Extend and idempotent Close endpoints

**Files:**
- Modify: `database-viewer/routes/web.php`
- Modify: `database-viewer/src/Http/ViewerController.php`
- Modify: `database-viewer/tests/ViewerTest.php`

**Interfaces:**
- Adds named authenticated POST routes `database-viewer.session.extend` and `database-viewer.session.close` under the existing server/database scope.
- Both accept exactly `{channel: string}` and return no-store JSON.
- Extend returns `{data: {expiresAt: string, maxExpiresAt: string, serverNow: string}}` after full current `ViewerAccess` authorization; all three timestamps come from the authoritative server operation.
- Close returns `{data: {closed: true}}` after identity/context ownership validation without requiring current database permission.

- [ ] **Step 1: Add failing Extend endpoint tests.** Assert exact payload validation, CSRF/auth/2FA middleware, current access reauthorization, authoritative `expiresAt`/`maxExpiresAt`/`serverNow`, one captured server clock, non-stacking, maximum cap, expired/closed 410 codes, and generic context mismatch.
- [ ] **Step 2: Add failing Close endpoint tests.** Assert immediate revocation, repeated success, success after `database.read`/tenant access removal, rejection for another user/context/channel, no SQL/AI call, and no effect on a second viewer.
- [ ] **Step 3: Run `phpunit -c database-viewer/phpunit.xml --filter='extend|close|session'` and verify the routes/actions are missing.**
- [ ] **Step 4: Add the two throttled routes and small controller actions.** Close resolves the route's server identity only for stored-context comparison and must not call the normal access resolver; Extend must call it.
- [ ] **Step 5: Re-run the lifecycle and full feature suites and verify exact response shapes/statuses.**
- [ ] **Step 6: Commit routes, actions, and tests with message `Add viewer Extend and Close controls`.**

### Task 6: Parent-page countdown and lifecycle behavior

**Files:**
- Modify: `database-viewer/resources/views/viewer.blade.php`
- Modify: `database-viewer/resources/js/viewer.mjs`
- Modify: `database-viewer/tests/viewer.test.mjs`
- Modify: `database-viewer/tests/bridge.test.mjs` only if lifecycle error envelope validation belongs in the bridge

**Interfaces:**
- Blade supplies data attributes for authoritative `expiresAt`, `maxExpiresAt`, `serverNow`, Extend URL, Close URL, and back URL while preserving iframe channel/origin/query/AI data.
- `viewer.mjs` owns cosmetic countdown, explicit Extend/Close fetches, lifecycle-error recognition, controls, request abortion, bridge disposal, and navigation.
- The parent recognizes exactly `SESSION_EXPIRED`, `SESSION_CLOSED`, and `TRANSACTION_NOT_ATOMIC`, then sends Studio only its existing strict `{error}` shape. Session codes are terminal; transaction-policy rejection is non-terminal.

- [ ] **Step 1: Refactor the viewer module behind exported, dependency-injectable lifecycle helpers and add failing fake-clock tests for `MM:SS` countdown.** Initialize remaining time from `expiresAt - serverNow`, then decrement with injected monotonic elapsed time. Prove client wall clocks ahead and behind the server both start at approximately 15 minutes, reaching zero changes display only, and the timer neither authorizes nor revokes anything.
- [ ] **Step 2: Add failing Extend tests for exact authenticated POST payload, disabled duplicate click, authoritative `expiresAt`/`maxExpiresAt`/`serverNow` replacement, monotonic-anchor reset, maximum display, error recovery, and no implicit extension from successful SQL/AI traffic.**
- [ ] **Step 3: Add failing Close tests for exact POST payload, idempotent UI handling, bridge disposal, pending-request abortion, control disabling, and navigation only after successful server response.**
- [ ] **Step 4: Add failing broker tests for all three approved codes.** Assert `SESSION_EXPIRED` and `SESSION_CLOSED` forward their fixed safe errors, mark the page unavailable, abort pending requests, dispose the bridge, and stop forwarding. Assert `TRANSACTION_NOT_ATOMIC` forwards only `This operation cannot be executed atomically.`, keeps the bridge/viewer active, and permits a later valid query. Unknown codes, mismatched messages, and any additional backend fields are not trusted or forwarded.
- [ ] **Step 5: Run `node --test database-viewer/tests/viewer.test.mjs database-viewer/tests/bridge.test.mjs` and verify lifecycle tests fail.**
- [ ] **Step 6: Render the status area in Blade and implement lifecycle helpers in `viewer.mjs`.** Keep `pagehide` cleanup and bfcache reload behavior; do not send Close from unload.
- [ ] **Step 7: Re-run all Node tests and the Blade feature assertions; verify they pass.**
- [ ] **Step 8: Commit UI and tests with message `Add temporary viewer session controls`.**

### Task 7: Bounded session pruning

**Files:**
- Create: `database-viewer/src/Console/Commands/PruneViewerSessionsCommand.php`
- Modify: `database-viewer/src/Services/ViewerSessionManager.php`
- Modify: `database-viewer/src/Providers/DatabaseViewerPluginProvider.php`
- Create: `database-viewer/tests/PruneViewerSessionsCommandTest.php`
- Modify: `database-viewer/tests/ViewerSessionManagerTest.php`

**Interfaces:**
- `ViewerSessionManager::pruneBatch(int $limit = 500): int` deletes at most the limit of rows expired or revoked at least 24 hours ago using indexed selections.
- `database-viewer:prune-sessions` loops bounded batches to completion and reports aggregate row count only.
- `create()` attempts at most one small pruning batch before opening the user-row-locked creation transaction (or only after it fully commits), and logs cleanup failure without blocking or deciding session creation.

- [ ] **Step 1: Add failing controlled-clock tests for retention boundaries.** Preserve active, recently expired, and recently revoked rows; prune rows exactly 24 hours old; cap one batch; handle expiry and revocation indexes without an unbounded OR scan.
- [ ] **Step 2: Add failing command tests for multi-batch completion, zero-row success, count-only output, and command registration in console mode.**
- [ ] **Step 3: Add a failing creation test proving opportunistic pruning executes one bounded pass outside the user-row-lock critical section, remains independent when creation later succeeds or hits the 20-viewer limit, and logs a simulated prune failure without preventing creation.**
- [ ] **Step 4: Run `phpunit -c database-viewer/phpunit.xml --filter='PruneViewerSessions|prun'` and verify missing behavior.**
- [ ] **Step 5: Implement two indexed candidate queries for old expiry and old revocation, deduplicate bounded IDs, delete by primary key, register the command, and call one small best-effort pass before the creation transaction.** Never run pruning while the user row is locked and never log channels or row context.
- [ ] **Step 6: Re-run pruning, session, and feature tests and verify they pass.**
- [ ] **Step 7: Commit pruning code and tests with message `Prune expired database viewer sessions`.**

### Task 8: Release documentation and deterministic 0.5.0 package

**Files:**
- Modify: `database-viewer/plugin.json`
- Modify: `database-viewer/README.md`
- Modify: `database-viewer/source-inspection.md`
- Modify: `database-viewer/verification.md`
- Modify: `tools/package-database-viewer.py`
- Modify: `tools/test_package_database_viewer.py`
- Generate: `dist/database-viewer-0.5.0.zip`
- Generate: `dist/database-viewer-0.5.0.zip.sha256`

**Interfaces:**
- Produces a deterministic archive containing every runtime migration/model/value object/exception/service/command/UI file and no tests, design documents, caches, secrets, or superseded `ViewerContext.php`.
- Documents transaction allowlist/rejection, pre-commit aggregate response validation, individual-query DDL, engine boundary, session lifetime/server time, explicit Extend, two-hour cap, Close, active limit, cleanup, current-permission checks, deployment migration, and observed validation only.

- [ ] **Step 1: Update packaging tests first.** Expect version 0.5.0, new runtime files including `BrokerResponseGuard.php`, removal of `ViewerContext.php`, required session/transaction/server-time documentation phrases, deterministic ordering, and secret exclusion.
- [ ] **Step 2: Run `python -m unittest tools.test_package_database_viewer` and verify it fails against 0.4.0 metadata/allowlist.**
- [ ] **Step 3: Bump `plugin.json` to 0.5.0, update source inspection with the Studio call-site inventory, and rewrite README/verification around the approved behavior and limitations.** Do not claim live MariaDB validation unless performed.
- [ ] **Step 4: Update the packager allowlist and archive test expectations for all new runtime files.**
- [ ] **Step 5: Run the complete PHP suite, both Node suites, and packaging suite; record exact results in `verification.md`.**
- [ ] **Step 6: Run `python tools/package-database-viewer.py` twice, compare checksums, inspect ZIP names/modes/CRCs, and verify the SHA-256 sidecar.**
- [ ] **Step 7: Run `git diff --check`, inspect `git status --short`, and use `git ls-files --stage` plus ZIP inspection to prove `database-viewer-ai-token.txt` is neither staged nor packaged.**
- [ ] **Step 8: Commit release metadata, runtime allowlist, documentation, tests, and generated release artifacts according to the repository's existing artifact policy with message `Release temporary atomic database viewer sessions`.**

### Task 9: Final security review and deployment readiness

**Files:**
- Review: every file changed by Tasks 1-8
- Modify: `database-viewer/verification.md` only for evidence gathered during final review

**Interfaces:**
- Produces a review-ready commit, verified package filename/checksum, exact test counts, and deployment instructions.

- [ ] **Step 1: Review the complete diff against every design section.** Trace request ordering, channel hashing, timestamp boundaries, Close-after-permission-loss, limit serialization, SQL root classification, rollback paths, logs, response shapes, and package contents.
- [ ] **Step 2: Run the complete PHP, Node, and Python validation matrix once after review fixes and capture exact pass counts.**
- [ ] **Step 3: If a disposable MariaDB is available, test two/three-statement commit, middle/final failure rollback on InnoDB, rejected DDL transaction envelope, single-query DDL, schema bootstrap, and unchanged read-only enforcement.** Record the engine/version and only observed results.
- [ ] **Step 4: Verify the migration through Pelican's plugin install/update path in a disposable Panel if available, including reload behavior for a pre-0.5 open viewer.**
- [ ] **Step 5: Rebuild once after any review fix and record the final `database-viewer-0.5.0.zip` checksum and archive entry count.**
- [ ] **Step 6: Commit review fixes/evidence with message `Verify database viewer 0.5.0 release`.**
- [ ] **Step 7: Prepare the completion report: source commits, every changed file, schema/indexes, lifetime/cap, Extend/Close/cleanup semantics, transaction/implicit-commit/explicit-control policy, test results, MariaDB validation, package/checksum, limitations, and recommended next task.**
