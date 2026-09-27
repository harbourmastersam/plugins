# Database Viewer Schema Bootstrap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the normal embedded MySQL Studio interface initialize for one Pelican-authorized database while permitting only the diagnostic query, current-database query, and exact six-statement schema bootstrap batch.

**Architecture:** Studio receives one validated database name and continues using its existing proxy-to-transaction path. The Pelican endpoint validates the complete request before connecting, classifies canonical browser statements into internal enums, and executes only executor-owned prepared SQL against the authorized `Database` model. The parent bridge performs structural result validation while the backend remains the policy authority.

**Tech Stack:** Next.js/React/TypeScript/Jest in `E:\Users\Admin\Documents\ChatGPT\studio`; Pelican Panel/Laravel/PHP 8.4/PHPUnit, browser ES modules/Node test runner, PDO MariaDB, and Python packaging in `E:\Users\Admin\Documents\ChatGPT\pelican-plugins`.

**Spec:** `docs/superpowers/specs/2026-09-27-database-viewer-schema-bootstrap-design.md`

## Global Constraints

- Keep the three exact request shapes from the design: diagnostic `SELECT 1`, exact `SELECT DATABASE() AS db`, and one transaction containing the six canonical metadata statements exactly once and in order.
- Never pass browser SQL into PDO. Policy code returns `AllowedQuery` enum values; executor code generates fixed SQL and binds the authorized Pelican database name.
- Validate the whole transaction and all request limits before opening a database connection. Execute accepted metadata statements sequentially on one request-local connection without SQL `BEGIN` or `COMMIT`.
- Keep `mode=probe` available as an explicit diagnostic route. The normal viewer must omit it.
- Keep `database.read` as the only permission in this phase. Do not add row browsing, writes, exports, dumps, database switching, general SQL, or new permissions.
- Preserve origin, iframe-window, channel, request-ID, duplicate-inflight, lifecycle reset, disposal, and generic-error protections.
- Use one central `BrokerLimits` definition for six statements, 2,048 statement bytes, 16,384 request bytes, 5,242,880 response bytes, and three-second connection and statement timeouts.
- Do not add `EmbedQueryable.batch()`; the existing `Studio` proxy already routes `schemas()` through one transaction envelope.

## Review Focus

- Database names containing apostrophes and multibyte characters must work; empty, duplicate, malformed, control-character, replacement-character, and over-64-code-point values must fail before driver construction.
- Request and statement byte limits must pass exactly at the boundary and fail one byte over without connecting.
- Empty PDO results, unavailable `getColumnMeta()`, large integers, binary values, and unsupported or non-finite values must serialize deterministically or fail safely.
- A serialized success response exactly 5 MiB must pass; one byte over must be discarded with the generic database error and no partial result.
- Navigation, iframe reload, disposal, and permission revocation must prevent stale transaction results and force authorization on the next broker request.

---

### Task 1: Require and propagate the selected database in normal Studio embeds

**Files:**
- Create: `E:\Users\Admin\Documents\ChatGPT\studio\src\lib\embed-database.ts`
- Modify: `E:\Users\Admin\Documents\ChatGPT\studio\src\app\(theme)\embed\[driver]\page-client.tsx`
- Modify: `E:\Users\Admin\Documents\ChatGPT\studio\src\app\(theme)\embed\[driver]\page-client.test.tsx`
- Create: `E:\Users\Admin\Documents\ChatGPT\studio\src\components\gui\studio.test.tsx`
- Modify: `E:\Users\Admin\Documents\ChatGPT\studio\docs\embedding.md`

**Interfaces:**
- Produce `parseEmbedDatabase(searchParams: Pick<URLSearchParams, "getAll">): { valid: true; value: string } | { valid: false }`.
- Consume its validated value in `createDatabaseDriver`; pass it only to `new MySQLLikeDriver(queryable, selectedDatabase)`.
- Preserve current construction for non-MySQL drivers and the exact `mode=probe` branch.
- Preserve the existing `Studio` proxy contract: `MySQLLikeDriver.schemas()` produces one six-statement `transaction()` call and zero individual `query()` calls.

- [ ] Add table-driven tests for one valid ASCII name, an apostrophe, multibyte names, 64 Unicode code points, empty input, two `database` values, 65 code points, ASCII controls, `U+007F`, `U+FFFD`, and malformed URL decoding behavior exposed by the page.
- [ ] Add page tests proving a valid normal MySQL embed supplies the database to `MySQLLikeDriver`, invalid configuration renders a generic `role="alert"` state without constructing the driver, probe mode bypasses normal driver construction, and non-MySQL embeds retain existing behavior.
- [ ] Add a component regression that renders `Studio` with an apostrophe-scoped `MySQLLikeDriver`, triggers `databaseDriver.schemas()` through `useStudioContext()`, and asserts one transaction with the six exact doubled-quote statements and zero individual query calls.
- [ ] Run the focused test and confirm the new expectations fail:

  ```powershell
  npm test -- --runInBand "src/app/(theme)/embed/[driver]/page-client.test.tsx" src/components/gui/studio.test.tsx
  ```

- [ ] Implement exact-one parsing with `getAll("database")`, Unicode code-point counting via `Array.from(value).length`, and rejection of `/[\u0000-\u001F\u007F\uFFFD]/u`.
- [ ] Parse probe mode before normal MySQL database validation so the diagnostic path remains independent.
- [ ] Pass the selected name into the existing MySQL driver constructor and add the safe configuration error UI.
- [ ] Update embedding documentation with the required single `database` parameter, the validation rules, and the retained probe URL.
- [ ] Re-run the focused Jest test and TypeScript typecheck:

  ```powershell
  npm test -- --runInBand "src/app/(theme)/embed/[driver]/page-client.test.tsx" src/components/gui/studio.test.tsx src/drivers/iframe-driver.test.ts
  npm run typecheck
  ```

- [ ] Commit in the Studio repository:

  ```powershell
  git add src/lib/embed-database.ts 'src/app/(theme)/embed/[driver]/page-client.tsx' 'src/app/(theme)/embed/[driver]/page-client.test.tsx' src/components/gui/studio.test.tsx docs/embedding.md
  git commit -m "Require database scope for MySQL embeds"
  ```

### Task 2: Add server-owned policy classification and centralized limits

**Files:**
- Create: `database-viewer/src/Enums/AllowedQuery.php`
- Create: `database-viewer/src/Services/BrokerLimits.php`
- Create: `database-viewer/src/Services/SchemaBootstrapPolicy.php`
- Create: `database-viewer/tests/SchemaBootstrapPolicyTest.php`

**Interfaces:**
- Produce enum cases `Diagnostic`, `CurrentDatabase`, `Schema`, `Tables`, `Columns`, `Constraints`, `ConstraintColumns`, and `Triggers`.
- Produce `classifyQuery(Database $database, string $statement): ?AllowedQuery` that can return only `Diagnostic` or `CurrentDatabase`.
- Produce `classifyTransaction(Database $database, array $statements): ?array`, documented as `list<AllowedQuery>|null`, returning the exact ordered six metadata enums or `null`.
- Produce comparison-only canonical SQL from the authorized model name with single quotes doubled. The classifier must never expose browser SQL to the executor.

- [ ] Write policy tests for exact diagnostic normalization, exact current-database SQL, exact six-statement order, apostrophe and multibyte database names, standalone metadata rejection, another database name, changed whitespace/case/clauses/comments, missing/reordered/duplicate/extra members, wrong scalar types, and arbitrary reads/writes.
- [ ] Write boundary tests for exactly six statements and for statement strings of exactly 2,048 UTF-8 bytes versus 2,049 bytes, including multibyte byte-count coverage.
- [ ] Run the focused suite and confirm it fails because the enum, limits, and policy do not exist:

  ```powershell
  wsl php /home/key/database-viewer-dev/panel/vendor/bin/phpunit database-viewer/tests/SchemaBootstrapPolicyTest.php
  ```

- [ ] Implement immutable `BrokerLimits` constants for all approved byte, count, and timeout limits.
- [ ] Implement canonical SQL generation and fail-closed whole-array classification. Check count, scalar types, and byte size before statement comparison.
- [ ] Keep `SELECT 1`'s existing narrow case/whitespace/single-semicolon normalization; compare every other operation exactly.
- [ ] Run the focused suite and existing PHP suite:

  ```powershell
  wsl php /home/key/database-viewer-dev/panel/vendor/bin/phpunit database-viewer/tests/SchemaBootstrapPolicyTest.php
  wsl php /home/key/database-viewer-dev/panel/vendor/bin/phpunit -c database-viewer/phpunit.xml
  ```

- [ ] Commit in the plugins repository:

  ```powershell
  git add database-viewer/src/Enums/AllowedQuery.php database-viewer/src/Services/BrokerLimits.php database-viewer/src/Services/SchemaBootstrapPolicy.php database-viewer/tests/SchemaBootstrapPolicyTest.php
  git commit -m "Add schema bootstrap policy"
  ```

### Task 3: Execute enum operations and serialize Studio-compatible result sets

**Files:**
- Create: `database-viewer/src/Services/DatabaseResultSerializer.php`
- Modify: `database-viewer/src/Services/QueryExecutor.php`
- Modify: `database-viewer/src/Services/MariaDbExecutor.php`
- Modify: `database-viewer/src/Providers/DatabaseViewerPluginProvider.php`
- Create: `database-viewer/tests/DatabaseResultSerializerTest.php`
- Create: `database-viewer/tests/MariaDbExecutorTest.php`

**Interfaces:**
- Change `QueryExecutor` to `execute(Database $database, AllowedQuery $operation): array` and `executeBatch(Database $database, array $operations): array`, with `$operations` documented as `list<AllowedQuery>` and the return as `list<array<string, mixed>>`.
- Produce `DatabaseResultSerializer::serialize(PDOStatement $statement, float $durationMs): array` returning Studio's `DatabaseResultSet` structure.
- `MariaDbExecutor` must independently reject non-standalone enums passed to `execute()` and any batch that is not the exact six-enum schema sequence.

- [ ] Write serializer tests for empty results, metadata-derived headers, unavailable or false `getColumnMeta()`, header fallback from row keys, text/integer/real/blob render hints, `null`, booleans, safe integers, integers outside JavaScript's safe range as decimal strings, binary strings, invalid UTF-8, non-finite floats, objects, arrays, and resources.
- [ ] Assert stats contain `rowsAffected: 0`, the actual `rowsRead`, `rowsWritten: null`, and nonnegative `queryDurationMs`, and omit `lastInsertRowid`.
- [ ] Write executor tests proving fixed prepared SQL is selected by enum, the authorized database is bound as a parameter, raw request strings cannot enter the API, one connection serves the complete batch, result order is preserved, no SQL transaction commands run, and an invalid enum sequence connects zero times.
- [ ] Add execution-failure tests for connection, prepare, execute, column metadata, and serialization errors; each must abort the complete operation without exposing exception text or partial results.
- [ ] Verify PDO options use disabled persistence and multi-statements, `utf8mb4`, `BrokerLimits::CONNECTION_TIMEOUT_SECONDS`, and `BrokerLimits::STATEMENT_TIMEOUT_SECONDS`.
- [ ] Run both focused suites and confirm failure:

  ```powershell
  wsl php /home/key/database-viewer-dev/panel/vendor/bin/phpunit database-viewer/tests/DatabaseResultSerializerTest.php database-viewer/tests/MariaDbExecutorTest.php
  ```

- [ ] Implement JSON-safe scalar conversion and deterministic column metadata fallback. Fail the entire result on unsupported values instead of substituting or truncating them.
- [ ] Map enums to executor-owned prepared SQL. Use parameter placeholders for database predicates and bind `$database->database`; keep `SELECT 1` and `SELECT DATABASE() AS db` fixed.
- [ ] Open one request-local PDO connection per call, execute batch statements sequentially, and return a complete ordered array only after all six succeed.
- [ ] Bind the updated interface and serializer through the plugin provider.
- [ ] Re-run focused tests and the complete PHP suite:

  ```powershell
  wsl php /home/key/database-viewer-dev/panel/vendor/bin/phpunit database-viewer/tests/DatabaseResultSerializerTest.php database-viewer/tests/MariaDbExecutorTest.php
  wsl php /home/key/database-viewer-dev/panel/vendor/bin/phpunit -c database-viewer/phpunit.xml
  ```

- [ ] Commit in the plugins repository:

  ```powershell
  git add database-viewer/src/Services/DatabaseResultSerializer.php database-viewer/src/Services/QueryExecutor.php database-viewer/src/Services/MariaDbExecutor.php database-viewer/src/Providers/DatabaseViewerPluginProvider.php database-viewer/tests/DatabaseResultSerializerTest.php database-viewer/tests/MariaDbExecutorTest.php
  git commit -m "Execute fixed schema metadata operations"
  ```

### Task 4: Accept only explicit query and transaction envelopes at the broker

**Files:**
- Modify: `database-viewer/src/Http/ViewerController.php`
- Modify: `database-viewer/resources/views/viewer.blade.php`
- Modify: `database-viewer/tests/ViewerTest.php`

**Interfaces:**
- Consume either `{ type: "query", statement, channel }` or `{ type: "transaction", statements, channel }`.
- Produce one `DatabaseResultSet` for query or an ordered six-element array for transaction.
- Build the normal iframe URL with exactly one RFC-3986-encoded `database` parameter and no `mode=probe`.

- [ ] Add HTTP tests that the viewer URL contains channel plus the selected database and never credentials, tokens, host details, or forced probe mode.
- [ ] Add request tests for valid diagnostic/current-database queries and the exact transaction; assert authorization and ViewerContext are rechecked on every call and revoked permission rejects before connection.
- [ ] Add rejection tests for missing/extra/mixed envelope fields, wrong types, standalone metadata, arbitrary SQL, wrong channel, switched server/database IDs, invalid batches, and exact 16,384 versus 16,385 raw-body bytes. Assert rejected requests never invoke the executor.
- [ ] Pin status handling: policy failures return HTTP 422; current authentication, authorization, and route-model failures retain 401/403/404; connection, PDO, metadata, serialization, and execution failures return HTTP 503 with exactly `Database query failed.`.
- [ ] Add response tests that measure the serialized complete JSON success body at exactly 5,242,880 bytes and one byte over. The boundary response must pass; the oversized response must be discarded and return HTTP 503 with exactly `Database query failed.` and no partial rows or exception text.
- [ ] Run the controller suite and confirm the new cases fail:

  ```powershell
  wsl php /home/key/database-viewer-dev/panel/vendor/bin/phpunit database-viewer/tests/ViewerTest.php
  ```

- [ ] Remove forced probe mode from both controller and view data. Generate the iframe URL using explicit RFC 3986 query encoding.
- [ ] Read and limit the raw request body before JSON decoding; validate an unambiguous envelope and all cheap limits before invoking authorization-dependent execution.
- [ ] Re-run ViewerAccess and ViewerContext validation, classify the complete request through `SchemaBootstrapPolicy`, then invoke only enum-based executor methods.
- [ ] Serialize the full success response before returning it and enforce the exact byte limit. Log only IDs, operation class, outcome, result count, and duration.
- [ ] Update the Blade copy to describe metadata-only Studio access and keep the diagnostic route available through its explicit URL.
- [ ] Re-run the focused and complete PHP suites, then Pint on changed PHP:

  ```powershell
  wsl php /home/key/database-viewer-dev/panel/vendor/bin/phpunit database-viewer/tests/ViewerTest.php
  wsl php /home/key/database-viewer-dev/panel/vendor/bin/phpunit -c database-viewer/phpunit.xml
  wsl /home/key/database-viewer-dev/panel/vendor/bin/pint --test --quiet database-viewer/src database-viewer/tests
  ```

- [ ] Commit in the plugins repository:

  ```powershell
  git add database-viewer/src/Http/ViewerController.php database-viewer/resources/views/viewer.blade.php database-viewer/tests/ViewerTest.php
  git commit -m "Broker exact schema bootstrap requests"
  ```

### Task 5: Generalize the parent bridge for validated transaction results

**Files:**
- Modify: `database-viewer/resources/js/bridge.mjs`
- Modify: `database-viewer/resources/js/viewer.mjs`
- Modify: `database-viewer/tests/bridge.test.mjs`
- Modify: `database-viewer/tests/viewer.test.mjs`

**Interfaces:**
- Forward structurally valid query and transaction requests with their explicit `type`, channel, and statement payload.
- Accept query result objects and transaction result arrays only after validating headers, stats, row shapes, JSON-safe scalars, result count, and response identity.
- Remove the `withProbeMode` export and load the controller-provided normal Studio URL unchanged.

- [ ] Add bridge tests for a valid current-database query, an ordered six-result transaction, response-length mismatch, malformed headers/stats/rows, nested values, non-finite values, duplicate inflight IDs, wrong origin/window/channel/type, iframe reload/reset during a pending transaction, disposal, and backend rejection of an unsupported query.
- [ ] Add viewer tests proving the iframe URL retains one encoded database value, never adds probe mode, sends explicit envelope type, and renders only safe generic status/error text.
- [ ] Run Node tests and confirm the new transaction and URL expectations fail:

  ```powershell
  node --test database-viewer/tests/bridge.test.mjs database-viewer/tests/viewer.test.mjs
  ```

- [ ] Replace fixed `SELECT 1` response validation with generic `DatabaseResultSet` structural validation plus recursive rejection of non-scalar row values.
- [ ] Validate a transaction response as an array whose length matches the submitted statement count; copy validated fields into fresh result objects before posting them to Studio.
- [ ] Preserve exact identity and lifecycle guards so a stale HTTP completion after navigation, reset, or disposal cannot reach the iframe.
- [ ] Let the backend remain the SQL policy authority: the bridge validates shape and bounds, forwards the request, and returns the backend's generic policy error.
- [ ] Remove `withProbeMode` and use the already-scoped iframe source from the controller.
- [ ] Run all Node tests:

  ```powershell
  node --test database-viewer/tests/*.test.mjs
  ```

- [ ] Commit in the plugins repository:

  ```powershell
  git add database-viewer/resources/js/bridge.mjs database-viewer/resources/js/viewer.mjs database-viewer/tests/bridge.test.mjs database-viewer/tests/viewer.test.mjs
  git commit -m "Bridge schema bootstrap transactions"
  ```

### Task 6: Version, package, and verify the complete release

**Files:**
- Modify: `database-viewer/plugin.json`
- Modify: `database-viewer/README.md`
- Modify: `database-viewer/source-inspection.md`
- Modify: `database-viewer/verification.md`
- Modify: `tools/package-database-viewer.py`
- Create: `tools/test_package_database_viewer.py`
- Create: `dist/database-viewer-0.2.0.zip`
- Create: `dist/database-viewer-0.2.0.zip.sha256`

**Interfaces:**
- Produce plugin version `0.2.0` and a deterministic allowlisted archive containing every new runtime file but excluding tests and developer documents as defined by the packager.
- Produce `package_plugin(repo: Path) -> tuple[Path, str]` and an explicit immutable `RUNTIME_FILES` inventory used by both packaging and its test.
- Document exact supported operations, permission boundary, limits, installation, probe URL, and the absence of row browsing/writes.

- [ ] Add packaging tests that assert every expected `0.2.0` runtime path appears exactly once, tests/plans/caches are absent, ZIP timestamps are fixed, all entries are regular files, the archive passes CRC validation, and the sidecar digest matches independently computed SHA-256.
- [ ] Run `python -m unittest tools.test_package_database_viewer` and confirm it fails because the packager has no explicit `RUNTIME_FILES` interface and still targets `0.1.3`.
- [ ] Refactor the packager to the specified callable interface and explicit path inventory, then add the four new runtime components to that inventory.
- [ ] Set version `0.2.0` and replace SELECT-1-only descriptions with the exact metadata-bootstrap policy.
- [ ] Update source inspection and verification records with current Studio/plugin commit bases, security boundaries, automated commands, and honest manual-verification status.
- [ ] Run the complete Studio verification:

  ```powershell
  Set-Location E:\Users\Admin\Documents\ChatGPT\studio
  npm test -- --runInBand
  npm run typecheck
  npm run lint
  npm run build
  ```

- [ ] Run the complete plugin verification:

  ```powershell
  Set-Location E:\Users\Admin\Documents\ChatGPT\pelican-plugins
  wsl php /home/key/database-viewer-dev/panel/vendor/bin/phpunit -c database-viewer/phpunit.xml
  node --test database-viewer/tests/*.test.mjs
  wsl /home/key/database-viewer-dev/panel/vendor/bin/pint --test --quiet database-viewer/src database-viewer/tests
  python -m unittest tools.test_package_database_viewer
  python tools/package-database-viewer.py
  ```

- [ ] Inspect the ZIP file list, reject unexpected paths, recompute SHA-256 independently, and verify it matches the sidecar.
- [ ] In the disposable beta38 Panel, install the packaged plugin, list all three plugin routes, and construct the Filament table action to catch packaging or registration regressions.
- [ ] With disposable MariaDB databases, run one empty-schema and one small-table browser check. Confirm both leave Connecting and show Studio; confirm the populated schema lists its table and columns; confirm row browsing receives a controlled policy error.
- [ ] Record whether each manual check was observed. Do not claim production connectivity from local or mocked results.
- [ ] Commit release sources and artifacts in the plugins repository:

  ```powershell
  git add database-viewer/plugin.json database-viewer/README.md database-viewer/source-inspection.md database-viewer/verification.md tools/package-database-viewer.py tools/test_package_database_viewer.py dist/database-viewer-0.2.0.zip dist/database-viewer-0.2.0.zip.sha256
  git commit -m "Release Database Viewer 0.2.0"
  ```

### Task 7: Review, publish, and deploy the matched pair

**Files:**
- Review: all Studio changes since `6fdb1d0688d51435e5f94d4376e30b6b16ff1762`
- Review: all plugin changes since `8f164434e4d269b945a19a0e699aa9375612628c`

**Interfaces:**
- Produce two pushed commits/branches whose tested versions match, a deployed Studio Worker, and the `0.2.0` plugin archive in the plugin repository.

- [ ] Use `superpowers:requesting-code-review` to review policy bypasses, browser identity checks, enum/executor separation, PDO serialization, boundary arithmetic, and test fidelity. Resolve every material finding and rerun affected tests.
- [ ] Confirm both repositories are clean, inspect final diffs and commit histories, and verify no secret, credential, generated dependency tree, or temporary test file is included.
- [ ] Push the Studio `develop` result and plugins `main` result to their configured remotes after confirming the intended branch/ref and fast-forward relationship.
- [ ] Load and follow the Cloudflare and Wrangler skills, deploy the tested Studio Worker, and verify the deployed revision and `/embed/mysql?mode=probe` diagnostic remain reachable.
- [ ] Do a production browser smoke test only where current authentication and safe test database access are available. Record observed behavior without exposing the channel URL or database credentials.
- [ ] Report the exact Studio commit, plugin commit, Worker deployment identifier, archive path/SHA-256, automated results, manual results, and the remaining requirement for the Panel administrator to import or upgrade the plugin archive.
