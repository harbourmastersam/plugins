# Database Viewer Atomic Transactions and Temporary Sessions Design

## Goal

Database Viewer 0.5.0 will give Studio's general `transaction` transport honest MariaDB transaction semantics for compatible statements and will replace browser-session viewer contexts with authoritative, revocable, short-lived viewer sessions.

The existing permission mapping remains unchanged:

- `database.read` grants read-only SQL;
- `database.read` plus `database.update` grants full SQL.

Database credentials remain inside Pelican. Every SQL and AI request continues through the authenticated Pelican parent page, and current Pelican authorization remains authoritative on every database operation.

## Source Baseline and Scope

The design is based on Pelican plugins commit `7fd85411051843a7a803538446013d444f9223b2` and Studio commit `1519a4c3d58c10f8e8f0a7cbe4181855793007de`.

This release changes the Database Viewer plugin only. Studio is not modified to bypass transaction restrictions. A later task may improve Studio's graphical DDL workflow, but that is outside this release.

The release does not add permissions, change the read/full access mapping, expose credentials, store SQL or AI content, or weaken the existing origin, iframe-window, channel, document, request-ID, CSRF, authentication, two-factor, authorization, timeout, size, or audit-log controls.

## Current Studio Transaction Usage

Studio uses `QueryableBaseDriver.transaction()` for several operations with different database semantics:

- `CommonSQLImplement.updateTableData()` converts grouped row operations into ordered `INSERT`, `UPDATE`, and `DELETE` statements and submits them in one transaction call. This is the principal operation that requires real all-or-nothing behavior.
- The table schema editor submits the statements from `createUpdateTableSchema()`. The MySQL driver produces `CREATE TABLE` or one or more `ALTER TABLE` statements.
- The database schema editor submits `CREATE DATABASE` or `ALTER DATABASE` through a transaction call.
- The view editor submits `CREATE VIEW`, or `DROP VIEW` followed by `CREATE VIEW`, through a transaction call.
- The trigger editor submits `CREATE TRIGGER`, or related drop/create statements, through a transaction call.
- Mass table deletion calls `DROP TABLE` as individual queries. Empty-table operations call `DELETE` as individual queries.
- MySQL schema discovery calls `batch()` with the six metadata queries. Studio's proxy currently forwards both `batch` and `transaction` to the transport transaction method, but Pelican recognizes this exact sequence before applying general SQL policy.

The graphical schema, view, trigger, and database editors therefore send DDL in a transaction envelope even though MariaDB DDL is not transactionally reversible. Database Viewer will reject those envelopes with a controlled error. A full-access user may still execute permitted DDL explicitly as an individual SQL-editor query.

## Canonical Schema Bootstrap

The six-query schema bootstrap remains a separate fixed operation. `SchemaBootstrapPolicy` must recognize the exact six statements for the selected database, and `MariaDbExecutor::executeBatch()` must replace them with the existing parameterized, server-owned metadata queries.

The executor uses one request-local connection and returns six result sets in order. It does not start a writable transaction because the operation is read-only and fixed. General transaction policy and execution must not absorb or weaken this path.

## Managed Transaction Policy

Only full-access viewers may submit a general transaction envelope. Read-only general transaction envelopes remain disabled.

Pelican validates the complete batch before opening MariaDB. The batch must first satisfy the existing shape, count, and per-statement byte limits. A dedicated server-side managed-transaction policy then lexes every statement while respecting whitespace, ordinary comments, quoted values, quoted identifiers, parentheses, and a single optional trailing semicolon. Executable comments, malformed quoting or nesting, multiple top-level statements, and ambiguous syntax fail closed.

The managed transaction allowlist is deliberately narrow:

- `SELECT`;
- `INSERT`;
- `UPDATE`;
- `DELETE`;
- `REPLACE`;
- `WITH` only when its top-level operation resolves to one of the preceding statement families.

An allowed root is necessary but not sufficient. The policy inspects the complete normalized token stream of every statement after identifying its root. It rejects unsafe clauses even when the statement begins with an allowed root, including `SELECT ... INTO OUTFILE`, `SELECT ... INTO DUMPFILE`, `SELECT ... FOR UPDATE`, and `LOCK IN SHARE MODE`. It also rejects an unsafe operation hidden in a supported `WITH` form. The policy must reuse or extend the same quote/comment/depth-aware inspection used by `GeneralSqlPolicy`; it must not implement eligibility as a first-keyword check.

This supports Studio's grouped row edits and transaction-compatible reads without admitting file output, explicit database locks, or other side effects outside the managed rollback contract. It rejects before a database connection is opened:

- DDL including `CREATE`, `ALTER`, `DROP`, `TRUNCATE`, and `RENAME`;
- administrative and maintenance statements;
- explicit transaction control including `BEGIN`, `START TRANSACTION`, `COMMIT`, `ROLLBACK`, `SAVEPOINT`, and `RELEASE SAVEPOINT`;
- `SET`, including autocommit changes;
- `LOCK TABLES`, `UNLOCK TABLES`, `SELECT ... FOR UPDATE`, `LOCK IN SHARE MODE`, and other explicit locking forms;
- file-output forms including `INTO OUTFILE` and `INTO DUMPFILE`;
- XA statements;
- `CALL`, stored-program execution, dynamic execution, and other syntax whose effects cannot be classified safely;
- known implicit-commit or otherwise non-atomic statement families such as `LOAD`, `GRANT`, `REVOKE`, `ANALYZE`, `CHECK`, `OPTIMIZE`, `REPAIR`, `FLUSH`, and `RESET`.

Root allowlisting plus full-statement forbidden-construct inspection is stronger than either a root check or denylist alone: newly introduced or unrecognized statement families remain rejected until explicitly reviewed, while unsafe clauses under an otherwise permitted root are also rejected. Every statement in the complete envelope must pass before the connector is called. A rejected well-formed batch returns HTTP 422 with `{ "error": "This operation cannot be executed atomically.", "code": "TRANSACTION_NOT_ATOMIC" }`; no statement is sent to MariaDB.

An ordinary full-access `query` retains its 0.4.0 behavior. Pelican does not apply the managed-transaction allowlist to it, so permitted DDL and transaction-control SQL remain available as individual statements. Because each request uses a non-persistent connection, a manual transaction cannot span multiple HTTP requests; this limitation will be documented.

## Managed Transaction Execution

After policy accepts the complete batch, `MariaDbExecutor::executeStatements()` creates one non-persistent PDO connection and calls `beginTransaction()`. It executes and serializes each statement sequentially on that connection, keeping the result array private until all statements succeed.

Before commit, the executor constructs the complete ordered result array and runs the same JSON encoding and `BrokerLimits::MAX_RESPONSE_BYTES` check used for the final `{ "data": ... }` broker envelope. Per-result serializer checks remain early memory and size defenses, but they do not substitute for this combined-envelope check. A shared response guard keeps the executor and controller on the same encoding and byte-count rule. The controller may repeat the final check as defense in depth.

The executor commits only after every preparation, execution, result serialization, complete-array construction, complete broker-envelope JSON encoding, and aggregate response-size check succeeds. If any of those steps or commit fails, it attempts `rollBack()` when PDO still reports an active transaction and rethrows the safe database or generic failure. A combined result that exceeds the response limit by even one byte therefore rolls back instead of committing a transaction whose response cannot be delivered. The endpoint returns no partial result array.

The schema-bootstrap executor does not enter this path. Single full queries retain their existing execution path. Single read-only queries continue to use a server-created MariaDB read-only transaction and unconditional rollback.

Pelican guarantees that it begins one MariaDB transaction, executes accepted statements on one connection, commits only after every statement succeeds, and issues rollback after a failure while PDO still reports an active transaction. Managed transaction atomicity applies to transactional database effects. Full rollback guarantees require every affected object and operation to participate in MariaDB transactions, such as InnoDB-backed DML. Database Viewer cannot make MyISAM or other non-transactional table changes reversible, and it cannot roll back external side effects caused by database functions or extensions. The allowlist and full-statement checks exclude the clearest unsafe operations without claiming stronger guarantees than MariaDB provides.

## Viewer Session Record

The plugin adds a `database_viewer_sessions` table through Pelican's plugin migration mechanism. Each row contains:

- `id`, an unsigned big integer primary key;
- `user_id`, `server_id`, and `database_id`, unsigned big integer context bindings;
- `channel_hash`, the lowercase 64-character SHA-256 digest of the random channel;
- `created_at`;
- `expires_at`;
- `last_activity_at`;
- nullable `revoked_at`.

Indexes are:

- a unique index on `channel_hash`;
- a composite index on `user_id`, `revoked_at`, and `expires_at` for active-viewer counting and lookup; this order deliberately places equality predicates before the expiry range and is the query-plan equivalent of the required user/expiry/revocation index;
- an index on `expires_at` for expiry pruning;
- an index on `revoked_at` for revoked-session pruning;
- context indexes needed by the exact lookup plan, including `server_id` and `database_id` where query analysis shows they are useful.

The record deliberately has no raw channel, SQL, AI prompt, AI response, database credential, password, or cached access mode. The existing channel is generated with 32 cryptographically random bytes and URL-safe base64 encoding. Its SHA-256 hash is stored; the raw 43-character channel remains only in the parent/iframe transport needed by the existing bridge.

`last_activity_at` is informational audit state. Successful session validation may update it, but SQL and AI activity never changes `expires_at`. Only the explicit Extend endpoint changes expiry.

## Creation, Limit, and Lifetime

Opening Database Viewer first performs the existing full Pelican authorization and database/server relationship checks, derives the current SQL mode, and then creates a fresh viewer session and channel. Before entering the concurrency-critical creation transaction, it may attempt one bounded, best-effort historical-session pruning pass. A pruning failure is logged and does not decide whether creation succeeds.

The session uses one server-side clock reading:

- `created_at = now`;
- `last_activity_at = now`;
- `expires_at = now + 15 minutes`;
- maximum absolute expiry is `created_at + 2 hours`.

The initial viewer response exposes that same clock reading as `serverNow`, alongside `expiresAt` and `maxExpiresAt`, so the browser derives a duration without trusting its wall clock.

A user may have at most 20 active viewer sessions, where active means not revoked and `expires_at > now`. Creation runs in a Pelican-database transaction and briefly locks the user's row before counting and inserting, serializing concurrent creation attempts for that user. Historical-session pruning never runs while this user-row lock is held. The 21st active viewer is rejected explicitly with HTTP 429; no existing viewer is silently revoked.

Multiple permitted viewers for the same user and database receive different channels and independent records. Extending or closing one does not affect another.

## Per-request Validation and Authorization

SQL and AI requests keep the authenticated web, session-authentication, two-factor, and CSRF middleware. Each request:

1. resolves and authorizes the current Pelican user, server, and database through `ViewerAccess`;
2. confirms current `database.read` and usable server state;
3. hashes the supplied channel and locates the exact viewer session;
4. verifies the same user, server, and database bindings;
5. rejects revoked or expired sessions;
6. records `last_activity_at` without changing expiry;
7. derives the current Full or Read-only mode from current Pelican permissions;
8. applies SQL/AI request policy and executes only after every check succeeds.

If `database.update` is removed, the next authorized SQL request is read-only. If `database.read` or server access is removed, the next SQL, AI, or Extend request fails before the broker or MariaDB is called.

Invalid identities and context mismatches remain generic authorization failures. An expired session returns HTTP 410 with `{ "error": "Database Viewer session expired.", "code": "SESSION_EXPIRED" }`. A revoked session returns HTTP 410 with `{ "error": "Database Viewer session closed.", "code": "SESSION_CLOSED" }`.

## Extend Session

The authenticated `POST .../session/extend` endpoint accepts the exact channel-bearing request shape. It performs the same current Pelican authorization as SQL and AI, then locks and validates the viewer-session row.

An active session receives:

`expires_at = min(now + 15 minutes, created_at + 2 hours)`.

The response returns authoritative `expiresAt`, `maxExpiresAt`, and `serverNow` ISO-8601 timestamps captured from the server-side operation. Repeated extensions do not stack unused time. Expired and revoked sessions cannot be extended and return their respective HTTP 410 codes. Once the absolute limit is reached, the viewer must be reopened to create a fresh session.

## Close Viewer

The authenticated `POST .../session/close` endpoint accepts the exact channel-bearing request shape and does not perform database access. It verifies the channel hash, owning user, and matching server/database viewer context, then sets `revoked_at` if it is null.

Close intentionally does not require current `database.read`, server usability, or tenant access, allowing a user whose database permission was just removed to close their own viewer. It grants no database access. A second Close for the same owned context succeeds idempotently. A mismatched user, channel, server, or database remains a generic authorization failure.

On success, the parent page disposes the bridge, aborts its local pending requests, disables the viewer controls, and navigates to the server database page. Closing one viewer has no effect on other viewer records. Requests already accepted by the server may finish, but every validation beginning after revocation fails with `SESSION_CLOSED`.

Browser unload and `pagehide` continue to dispose client resources but are not relied upon for revocation. Abandoned viewers expire naturally.

## Parent-page Lifecycle UI

The Pelican Blade view receives authoritative initial `expiresAt`, `maxExpiresAt`, and `serverNow` values, plus the Extend URL, Close URL, database-page return URL, and current access label. It renders a compact status area containing the access label, cosmetic countdown, Extend button, Close button, and status text above the unchanged Studio iframe.

The viewer JavaScript computes the initial remaining duration as `expiresAt - serverNow`, anchors that duration to a browser monotonic clock such as `performance.now()`, and decrements from elapsed monotonic time. Client wall-clock skew therefore does not turn a fresh 15-minute lease into an immediate expiry or an excessive countdown. Extend returns fresh `expiresAt`, `maxExpiresAt`, and `serverNow` values and resets both the duration and monotonic anchor.

The countdown is cosmetic and never authorizes, revokes, extends, or closes a viewer. Reaching `00:00` changes display state only; it does not dispose the bridge or decide whether a request may run. The server's `SESSION_EXPIRED` response remains authoritative.

The parent explicitly recognizes only three exact application codes with fixed bounded error strings. `SESSION_EXPIRED` and `SESSION_CLOSED` forward the safe error to the matching Studio request, mark the viewer unavailable, abort pending requests, dispose the bridge, and disable further forwarding. `TRANSACTION_NOT_ATOMIC` forwards `{"error":"This operation cannot be executed atomically."}` through Studio's existing strict error shape but leaves the bridge, viewer, and later requests active. The machine code is consumed by the parent and is not added to Studio's postMessage protocol.

The bridge retains exact origin, `contentWindow`, channel, document nonce, request-ID, duplicate-inflight, response-shape, reset, and disposal protections. Unknown codes, mismatched fixed messages, and broker envelopes with arbitrary additional fields are untrusted and collapse to the existing generic error; no additional backend fields are forwarded to Studio. A valid query after `TRANSACTION_NOT_ATOMIC` proceeds normally.

## Cleanup

Rows remain available briefly for idempotent Close and useful audit timestamps. A record becomes pruneable when its expiry or revocation timestamp is at least 24 hours old.

The plugin registers an explicit `database-viewer:prune-sessions` Artisan command. It deletes pruneable IDs in bounded indexed batches until no rows remain and reports only counts, never channels or context data. Viewer creation also performs at most one small bounded pruning pass so active installations clean up without depending exclusively on scheduler configuration. This best-effort pass runs before the user-row creation transaction, or after it has fully committed, and never while the per-user lock is held. Its failure is logged, remains independent of viewer-creation success or rejection, and does not make an otherwise authorized viewer creation fail.

The command can be scheduled by an operator through Pelican's normal scheduler. The release does not add an unbounded request-time delete.

## Errors and Audit Logging

Session errors use only fixed bounded messages and codes. Transaction-policy rejection uses the exact HTTP 422 `TRANSACTION_NOT_ATOMIC` envelope defined above. Existing safe MariaDB diagnostics remain available for statements that reach MariaDB. No response reveals hashes, row IDs, credentials, SQL, filesystem paths, or stack traces.

Existing SQL-free and prompt-free logging continues. Session creation, extension, closure, limit rejection, and pruning log only bounded metadata and counts. SQL and AI logs retain user/server/database IDs, access mode where applicable, request kind, statement count, duration, outcome, affected-row totals, and result count without SQL text, values, result rows, credentials, prompts, or responses.

## Testing

Policy and executor tests prove complete prevalidation, accepted DML/read roots, CTE handling, rejection of implicit-commit and transaction-control families, one begin/commit after all successful statements, rollback for middle/final/serialization failures, and pre-commit aggregate response validation. Two individually valid results whose combined envelope is oversized must roll back; an exact-limit combined envelope commits; a one-byte overflow and JSON encoding failure roll back; no failure returns partial results. Tests also prove normal connection release, unchanged read-only behavior, and isolation of schema bootstrap from the managed transaction path.

Feature tests use controlled Laravel clocks to prove 15-minute creation, unique concurrent viewer identities, context binding, expiry/revocation errors, no SQL or AI call after invalidation, explicit-only expiry changes, current-permission re-evaluation, Extend capping and reauthorization, idempotent Close after permission loss, concurrent-viewer isolation, the concurrency-safe 20-viewer limit, and pruning.

JavaScript tests prove server-time-based countdown rendering under client wall-clock skew, authoritative Extend refresh, zero-time display without client-side revocation, Close navigation/disposal, terminal session-code handling, non-terminal transaction-policy handling followed by a valid query, rejection of unknown/extra backend fields, pending-request cleanup, and preservation of existing bridge validation.

Packaging tests require the migration, model, session services, command, routes, assets, and documentation in a deterministic 0.5.0 archive and SHA-256 sidecar. The full PHP, Node, and packaging suites run before release. MariaDB integration results are reported only if a disposable MariaDB instance is actually used.

## Deployment and Compatibility

The plugin version becomes 0.5.0. Updating through Pelican's plugin workflow runs the new plugin migration. Deployment then clears Laravel caches and restarts long-lived PHP and queue processes before verification.

Existing 0.4.0 browser-session contexts are intentionally not migrated. Opening the updated viewer creates a new persistent temporary session. Existing already-open viewers must be reloaded after deployment.

The release documentation will state the 15-minute lifetime, explicit Extend behavior, two-hour maximum, idempotent Close behavior, 20-viewer limit, 24-hour cleanup retention, current-permission checks, managed transaction allowlist, individual-query DDL behavior, and transactional-storage-engine limitation.
