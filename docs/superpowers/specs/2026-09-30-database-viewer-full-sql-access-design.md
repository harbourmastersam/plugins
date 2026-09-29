# Database Viewer Full SQL Access Design

## Goal

Turn Database Viewer from a schema-discovery transport into a practical database console comparable to phpMyAdmin. Server owners and subusers with both `database.read` and `database.update` may execute any SQL permitted by the selected database account. Subusers with only `database.read` may inspect schema and data but may not change database state.

The integration continues to keep database credentials inside Pelican. Outerbase sends SQL through the channel-bound parent bridge, Pelican reauthorizes every request, and Pelican opens the request-local MariaDB connection.

## Authorization

Opening Database Viewer requires the existing `database.read` permission. Each query or batch request repeats tenant, server-state, database-ownership, and `database.read` checks.

The broker determines the execution mode for every request:

- The server owner has full access.
- A subuser with both `database.read` and `database.update` has full access.
- A subuser with `database.read` but without `database.update` has read-only access.

Permission changes take effect on the next request. The random viewer channel remains bound to the authenticated user, server, and database and does not preserve an earlier access level.

The viewer header states either **Full SQL access** or **Read-only SQL access**. The header is informational; the server-side permission check is authoritative.

## Request Contract

The existing query endpoint continues to accept the two Outerbase request forms:

- `query`: one SQL statement;
- `transaction`: an ordered list of SQL statements, treated as a batch.

The schema bootstrap remains a valid six-statement batch for every authorized viewer. General batches are available only in full-access mode. Read-only users may submit one general read statement at a time.

Limits are:

- 64 KiB per statement;
- 100 statements per batch;
- 1 MiB for the raw JSON request;
- 5 MiB for the complete serialized response;
- a three-second connection timeout;
- a 30-second MariaDB statement timeout.

Empty statements, malformed arrays, non-string statements, and requests outside these limits fail before a database connection is opened. PDO native multi-statements remain disabled. A batch executes each supplied statement separately on one connection, which avoids ambiguous result-set handling and retains the statement boundaries produced by Outerbase.

## Full-Access Execution

In full-access mode, Pelican prepares and executes each statement as supplied. It does not try to classify or rewrite the SQL. The MariaDB account stored by Pelican remains the final database-level authority and may still deny operations for which it lacks privileges.

All MariaDB statement families are allowed, including reads, writes, DDL, grants when the account permits them, stored-program operations, and transaction-control statements. Batches execute sequentially on one connection and stop at the first failure. The broker does not add an implicit transaction. Explicit `START TRANSACTION`, `COMMIT`, and `ROLLBACK` statements control transaction boundaries. If the connection closes with an uncommitted transaction, MariaDB rolls it back; earlier auto-committed statements and DDL remain applied.

`DELIMITER` is a client directive rather than MariaDB SQL and is not supported by the transport. Outerbase must send a stored-program definition as one statement if its editor supports that syntax.

## Read-Only Execution

Read-only mode accepts a single statement beginning with `SELECT`, `WITH`, `SHOW`, `DESCRIBE`, `DESC`, or `EXPLAIN`, after skipping leading whitespace and SQL comments. The classifier rejects multiple top-level statements and rejects read syntax that requests side effects, including `INTO OUTFILE`, `INTO DUMPFILE`, `FOR UPDATE`, and `LOCK IN SHARE MODE`.

Classification is a first defense rather than the only enforcement. Pelican also starts a MariaDB read-only transaction before executing the statement and rolls it back afterward. MariaDB therefore rejects writes reached through writable CTEs, stored functions, or syntax the classifier does not understand. The connection is non-persistent and is discarded after the request.

Read-only users retain the fixed schema bootstrap transaction. Pelican continues replacing those six canonical metadata statements with server-owned parameterized SQL so schema discovery cannot escape the selected database.

## Results

The result serializer supports both result-producing and non-result-producing statements.

For result sets it returns column metadata, JSON-safe scalar rows, `rowsRead`, zero `rowsAffected`, and the measured duration. For writes and DDL it returns no columns or rows, the PDO affected-row count, `rowsWritten` when meaningful, and the measured duration. Inserts include a numeric `lastInsertRowid` only when MariaDB returns a JavaScript-safe integer; larger identifiers are omitted rather than rounded.

The serializer reads incrementally and accounts for the encoded response size while rows are collected. It aborts the entire response before returning partial data when the 5 MiB limit is exceeded. Unsupported values and invalid UTF-8 also fail the entire result.

A batch returns one result object for each completed statement in the original order. If any statement fails, the endpoint returns an error for the batch and no partial result array. This does not reverse database effects already committed by earlier statements.

## Errors and Logging

Authorization, invalid channel, malformed request, connection, response-limit, and internal failures use generic messages. MariaDB statement errors return a bounded diagnostic containing the SQLSTATE or vendor code and MariaDB message so users can correct syntax, constraint, and permission errors in Outerbase. Diagnostics are stripped of control characters, capped at 2 KiB, and never include the DSN, username, password, PHP paths, stack traces, or the submitted SQL.

The browser bridge copies only a bounded error string from the broker response to the matching Outerbase request. It still validates the exact origin, iframe window, channel, document nonce, request ID, operation, and result shape.

Application logs record the user, server, database, access mode, request kind, statement count, duration, success or failure, affected-row totals, and result count. Logs omit SQL text, parameters, database credentials, returned rows, AI prompts, and AI responses.

## AI Assistant

The existing server-mediated Workers AI path is unchanged. Generated SQL enters the editor and receives no special execution privilege. When the user runs it, the same current Pelican permission and SQL-mode checks apply as for hand-written SQL.

## Code Structure

- `ViewerAccess` resolves the selected database and derives the current read-only or full access mode.
- A dedicated SQL policy validates request shape, applies the fixed schema-bootstrap exception, and classifies read-only statements without coupling policy code to PDO execution.
- `QueryExecutor` gains general single-statement and batch operations that accept an explicit access mode.
- `MariaDbExecutor` owns connection configuration, read-only transaction enforcement, sequential batch behavior, timing, and statement execution.
- `DatabaseResultSerializer` handles result and command statements and enforces incremental response limits.
- `ViewerController` validates envelopes and limits, reauthorizes, delegates policy and execution, maps safe database errors, and logs metadata.
- `bridge.mjs` accepts bounded general batches and forwards bounded broker error messages.
- `viewer.blade.php` displays the current access level.

## Testing

PHP feature tests cover owners, read-only subusers, full-access subusers, revoked permissions, foreign databases, suspended servers, stale channels, request bounds, response bounds, safe error mapping, and credential non-disclosure.

Policy tests cover leading comments, CTE reads, `SHOW`, `DESCRIBE`, `EXPLAIN`, multiple statements, write statements, file output, row locks, stored procedure calls, malformed SQL, and schema-bootstrap recognition.

Executor and serializer tests cover reads, inserts, updates, deletes, DDL, affected rows, insert IDs, explicit commit and rollback, stop-on-error batches, MariaDB read-only enforcement, timeouts, large results, unsupported values, and invalid UTF-8. Tests use mocked PDO for deterministic edge cases and a MariaDB integration test when the repository's integration environment is available.

JavaScript bridge tests cover arbitrary statement sizes within the new bounds, batches from one through 100 statements, malformed envelopes, response and error validation, duplicate request IDs, iframe reload isolation, and pending-request cleanup.

The release check runs the plugin PHP suite against the matching Pelican checkout, the Node bridge suite, packaging tests, and a manual disposable-Panel verification for owner, read-only subuser, and full-access subuser behavior.

## Deployment

The plugin version is incremented and a new deterministic archive is produced. Updating a running Panel requires importing the archive through Pelican's plugin update workflow, clearing Laravel caches, and restarting long-lived PHP and queue workers so the new provider, routes, controller, and configuration load together.

No database migration or new credential is required. The existing `DATABASE_VIEWER_AI_TOKEN` remains unchanged.
