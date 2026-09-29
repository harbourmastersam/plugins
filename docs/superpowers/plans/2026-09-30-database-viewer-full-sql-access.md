# Database Viewer Full SQL Access Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide phpMyAdmin-style SQL execution while giving owners and `database.read` + `database.update` subusers full access and keeping `database.read`-only subusers read-only.

**Architecture:** Pelican derives an access mode on every request, validates general SQL separately from the fixed schema bootstrap, and executes authorized statements through request-local PDO connections. Full users may run sequential batches; read-only users are restricted by both a SQL classifier and a MariaDB read-only transaction. The existing channel-bound Outerbase bridge carries bounded results and safe database diagnostics.

**Tech Stack:** PHP 8.3+, Laravel/Pelican Panel beta38, PDO MySQL/MariaDB, Blade, JavaScript ES modules, PHPUnit, Node test runner, Python packaging tests.

**Spec:** `docs/superpowers/specs/2026-09-30-database-viewer-full-sql-access-design.md`

## Global Constraints

- Opening the viewer always requires `database.read`; full access requires owner status or both `database.read` and `database.update` on the selected server.
- Reauthorize the user, tenant, server state, selected database, and channel on every request.
- Keep credentials, SQL text, rows, AI prompts, and AI responses out of HTML, URLs, JavaScript, and application logs.
- Accept at most 64 KiB per statement, 100 statements per batch, 1 MiB raw request, and 5 MiB serialized response.
- Use a three-second connection timeout and 30-second statement timeout; keep PDO persistence and native multi-statements disabled.
- Preserve the fixed, parameterized six-statement schema bootstrap for all viewers.
- Do not add a migration, Composer package, npm dependency, or second database credential.

## Review Focus

- A subuser losing `database.update` while the viewer is open must become read-only on the next request; Task 1 pins this with a feature test.
- Read-looking SQL containing comments, semicolons, file output, row locks, or writable CTE behavior must not escape read-only enforcement; Tasks 2 and 4 cover application and MariaDB defenses.
- A failed batch after an earlier auto-committed statement must return no partial result array without claiming rollback; Tasks 4 and 5 pin stop-on-error behavior.
- Large or binary result values must fail before partial JSON reaches Outerbase and without excessive `fetchAll` memory use; Task 3 covers incremental serialization.
- MariaDB errors may help the user but must never expose DSNs, credentials, PHP paths, control characters, or submitted SQL; Tasks 4, 5, and 6 cover sanitization and transport.

---

### Task 1: Per-request SQL access mode

**Files:**
- Create: `database-viewer/src/Enums/SqlAccessMode.php`
- Modify: `database-viewer/src/Services/ViewerAccess.php`
- Modify: `database-viewer/src/Http/ViewerController.php`
- Modify: `database-viewer/resources/views/viewer.blade.php`
- Test: `database-viewer/tests/ViewerTest.php`

**Interfaces:**
- Produces: `SqlAccessMode::{ReadOnly, Full}`.
- Produces: `ViewerAccess::resolve(?User $user, string $serverKey, string $databaseId): array{Server, Database, SqlAccessMode}`.
- Consumers in later tasks use the third tuple item for policy and execution.

- [ ] **Step 1: Add failing feature tests for access derivation and display.** Assert owners and read+update subusers see `Full SQL access`, read-only subusers see `Read-only SQL access`, users without read remain denied, and revoking update changes the next request to read-only.
- [ ] **Step 2: Run `phpunit -c database-viewer/phpunit.xml --filter='access|permission'` against the Pelican checkout and verify the new assertions fail because no mode exists.**
- [ ] **Step 3: Add the enum, return the mode from `ViewerAccess::resolve`, update controller tuple destructuring, and pass a fixed banner string to the view.** Use Pelican's current owner/permission APIs; do not infer mode from client data.
- [ ] **Step 4: Re-run the focused tests and verify they pass.**
- [ ] **Step 5: Commit with message `Add database viewer SQL access modes`.**

### Task 2: General SQL policy and request bounds

**Files:**
- Create: `database-viewer/src/Services/GeneralSqlPolicy.php`
- Modify: `database-viewer/src/Services/BrokerLimits.php`
- Modify: `database-viewer/src/Services/SchemaBootstrapPolicy.php`
- Create: `database-viewer/tests/GeneralSqlPolicyTest.php`
- Modify: `database-viewer/tests/SchemaBootstrapPolicyTest.php`

**Interfaces:**
- Produces: `GeneralSqlPolicy::allowsReadOnly(string $statement): bool`.
- Produces: `GeneralSqlPolicy::validStatement(string $statement): bool` and `validBatch(array $statements): bool` for exact type/count/byte checks.
- Produces constants `MAX_STATEMENT_BYTES = 65536`, `MAX_BATCH_STATEMENTS = 100`, `MAX_REQUEST_BYTES = 1048576`, `MAX_RESPONSE_BYTES = 5242880`, `STATEMENT_TIMEOUT_SECONDS = 30`.
- Existing `SchemaBootstrapPolicy` remains the only recognizer for the canonical metadata batch.

- [ ] **Step 1: Write failing policy tests.** Accept plain/comment-prefixed `SELECT`, read CTEs, `SHOW`, `DESCRIBE`, `DESC`, and `EXPLAIN`; reject empty SQL, multiple top-level statements, DML/DDL/CALL, `INTO OUTFILE`, `INTO DUMPFILE`, `FOR UPDATE`, and `LOCK IN SHARE MODE`. Pin 64 KiB, 100-statement, and 1 MiB boundaries.
- [ ] **Step 2: Run the two policy test files and verify failures identify the missing policy and old limits.**
- [ ] **Step 3: Implement a small lexical scanner that skips quoted strings, quoted identifiers, whitespace, and SQL comments while finding top-level tokens and statement separators.** It must classify only the documented read forms; ambiguity returns false.
- [ ] **Step 4: Update broker constants and keep canonical schema recognition independent of general SQL classification.**
- [ ] **Step 5: Run the focused policy tests and verify they pass.**
- [ ] **Step 6: Commit with message `Authorize bounded general SQL requests`.**

### Task 3: Incremental result and command serialization

**Files:**
- Modify: `database-viewer/src/Services/DatabaseResultSerializer.php`
- Modify: `database-viewer/tests/DatabaseResultSerializerTest.php`

**Interfaces:**
- Produces: `DatabaseResultSerializer::serialize(PDOStatement $statement, float $durationMs, string|false $lastInsertId = false): array`.
- Result queries return headers/rows with `rowsRead`; command queries return empty headers/rows with `rowsAffected` and `rowsWritten` from `rowCount()`.
- Optional `lastInsertRowid` is emitted only for a decimal identifier within JavaScript's safe integer range.

- [ ] **Step 1: Replace fetch-all assumptions with failing tests for incremental `fetch`, write/DDL stats, safe and oversized insert IDs, encoded-size overflow, invalid UTF-8, and unsupported values.**
- [ ] **Step 2: Run `DatabaseResultSerializerTest` and verify it fails on the new method behavior.**
- [ ] **Step 3: Implement row-by-row serialization with conservative encoded-byte accounting and a final exact JSON-size check by the caller.** Abort rather than truncate; never return partial rows.
- [ ] **Step 4: Run the serializer tests and verify they pass.**
- [ ] **Step 5: Commit with message `Serialize database query and write results`.**

### Task 4: Full and read-only PDO execution

**Files:**
- Create: `database-viewer/src/Exceptions/DatabaseStatementException.php`
- Modify: `database-viewer/src/Services/QueryExecutor.php`
- Modify: `database-viewer/src/Services/MariaDbExecutor.php`
- Create: `database-viewer/tests/MariaDbExecutorTest.php` if absent; otherwise modify it

**Interfaces:**
- Produces: `QueryExecutor::executeStatement(Database $database, string $statement, SqlAccessMode $mode): array`.
- Produces: `QueryExecutor::executeStatements(Database $database, array $statements, SqlAccessMode $mode): array`.
- Keeps existing fixed-operation methods for parameterized schema discovery.
- Produces: `DatabaseStatementException::fromPdo(PDOException $exception, string $statement): self` and `diagnostic(): string`, with the submitted statement removed and a control-free diagnostic of at most 2048 bytes.

- [ ] **Step 1: Add failing executor tests for SELECT, insert/update/delete, DDL, affected rows, insert ID, ordered batches, explicit transaction control, stop-on-first-error, connection reuse, and unchanged fixed metadata replacement.**
- [ ] **Step 2: Add failing read-only tests showing a read transaction begins and rolls back, while a simulated MariaDB write reached through read syntax fails without commit.**
- [ ] **Step 3: Add failing diagnostic tests for SQLSTATE/vendor messages, control characters, SQL text, credentials, DSNs, and PHP paths.**
- [ ] **Step 4: Run `MariaDbExecutorTest` and verify the new interface and behavior fail.**
- [ ] **Step 5: Implement separate general execution methods on the existing non-persistent connection.** For read-only mode issue MariaDB's next-transaction read-only command, begin, execute, serialize, and roll back in `finally`; full batches execute sequentially without an implicit transaction. Pass the exact statement only to `DatabaseStatementException::fromPdo` so it can redact any echoed SQL before the diagnostic leaves the executor.
- [ ] **Step 6: Convert only prepare/execute database exceptions into bounded `DatabaseStatementException`; keep connection and internal failures generic.**
- [ ] **Step 7: Run executor and serializer tests and verify they pass.**
- [ ] **Step 8: Commit with message `Execute authorized MariaDB statements`.**

### Task 5: Broker integration, authorization, and audit metadata

**Files:**
- Modify: `database-viewer/src/Http/ViewerController.php`
- Modify: `database-viewer/tests/ViewerTest.php`

**Interfaces:**
- Consumes the Task 1 mode, Task 2 policy, Task 3 result shape, and Task 4 executor/diagnostic.
- Query errors return `{error: string}`; safe MariaDB diagnostics use HTTP 422, policy errors use HTTP 422, oversized requests use HTTP 413, and internal/connection failures use generic HTTP 503.

- [ ] **Step 1: Add failing feature tests for owner full SQL, read+update full SQL, read-only general reads, read-only write denial before execution, full batches of 1 and 100 statements, oversized requests, schema bootstrap, and permission revocation between requests.**
- [ ] **Step 2: Add failing tests for safe 422 MariaDB diagnostics, generic connection errors, response overflow, stop-on-error batches with no partial result array, and logs that omit SQL/rows/credentials while recording mode/count/duration/outcome.**
- [ ] **Step 3: Run `ViewerTest` and verify the general requests fail under the old allowlist.**
- [ ] **Step 4: Refactor `query` into small validation, authorization, execution, response-limit, and logging helpers.** Route fixed schema operations to the old parameterized path; route read-only single statements through both Task 2 and Task 4 defenses; route full statements/batches through Task 4.
- [ ] **Step 5: Run `ViewerTest` and the complete PHP plugin suite; verify all tests pass without notices.**
- [ ] **Step 6: Commit with message `Broker full and read-only database SQL`.**

### Task 6: Outerbase bridge contract and error display

**Files:**
- Modify: `database-viewer/resources/js/bridge.mjs`
- Modify: `database-viewer/tests/bridge.test.mjs`

**Interfaces:**
- Accepts query statements through 64 KiB and dense transaction arrays of 1 through 100 statements.
- Accepts only `{data: ...}` success shapes or a bounded `{error: string}` failure shape from Pelican.
- Posts the safe error string to the exact matching Outerbase request; malformed errors become `Database query failed.`.

- [ ] **Step 1: Add failing bridge tests for boundary-sized statements, 1/6/100-statement batches, zero/101/sparse/oversized batches, safe broker diagnostics, oversized/control-bearing errors, and existing document-nonce isolation.**
- [ ] **Step 2: Run `node --test database-viewer/tests/bridge.test.mjs` and verify failures reflect the six-statement/generic-error contract.**
- [ ] **Step 3: Update request validation and error copying without adding browser-side SQL authorization.** Keep origin, iframe window, channel, request ID, document nonce, duplicate, reset, and disposal checks unchanged.
- [ ] **Step 4: Run all Node plugin tests and verify they pass.**
- [ ] **Step 5: Commit with message `Support general SQL in database viewer bridge`.**

### Task 7: Release metadata, documentation, and package verification

**Files:**
- Modify: `database-viewer/plugin.json`
- Modify: `database-viewer/README.md`
- Modify: `database-viewer/verification.md`
- Modify: `tools/package-database-viewer.py` only if version discovery is not already manifest-driven
- Modify: packaging tests under `tools/` if the archive allowlist changes

**Interfaces:**
- Produces a deterministic versioned ZIP and SHA-256 sidecar containing only runtime files.
- Documents the read/full permission split, execution limits, MariaDB privilege boundary, non-atomic batch behavior, safe errors, AI behavior, and required cache/process restart.

- [ ] **Step 1: Update documentation assertions or packaging tests first so they fail against version 0.3.0 and metadata-only wording.**
- [ ] **Step 2: Bump the plugin minor version, rewrite user-facing copy around full/read-only SQL, and record validation evidence without claiming unrun production checks.**
- [ ] **Step 3: Run the complete PHP suite, complete Node suite, and `python -m unittest tools.test_package_database_viewer`; verify all pass.**
- [ ] **Step 4: Run `python tools/package-database-viewer.py`, inspect archive contents, and verify the generated checksum.**
- [ ] **Step 5: Run `git diff --check` and inspect `git status` to ensure the local `database-viewer-ai-token.txt` is not staged or packaged.**
- [ ] **Step 6: Commit with message `Release database viewer full SQL access`.**

### Task 8: Final review and deployment readiness

**Files:**
- Review all files changed by Tasks 1-7
- Update: `database-viewer/verification.md` only for evidence gathered in this task

**Interfaces:**
- Produces a review-ready branch, a verified archive path/checksum, and explicit production deployment steps.

- [ ] **Step 1: Review the branch against every requirement in the approved spec, focusing on authorization bypass, SQL lexical edge cases, transaction semantics, error leakage, and response memory use.**
- [ ] **Step 2: Run the full validation matrix once more after review fixes; capture exact pass counts and artifact checksum.**
- [ ] **Step 3: If a disposable MariaDB integration environment is available, verify owner DDL/write/read, read+update DDL/write/read, read-only SELECT/SHOW, read-only write denial, explicit rollback, and a failing batch. Record only observed results.**
- [ ] **Step 4: Commit any review fixes and verification evidence with message `Verify database viewer full SQL release`.**
- [ ] **Step 5: Prepare deployment instructions: import the archive through Pelican's update flow, clear Laravel caches, restart long-lived PHP and queue workers, then verify all three permission modes before enabling general use.**
