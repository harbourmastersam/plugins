# Database Viewer Schema Bootstrap Design

## Goal

Open the real embedded MySQL Studio interface for one Pelican-selected MariaDB database while permitting only the metadata reads required for Studio startup. Arbitrary SQL, table-row reads, writes, exports, dumps, database switching, and unrestricted `information_schema` access remain unavailable.

The existing `mode=probe` connection test remains available as a diagnostic path. The normal Pelican Database Viewer stops selecting it.

## Current Source Findings

The design is based on `harbourmastersam/plugins` `main` at `8f164434e4d269b945a19a0e699aa9375612628c` and `harbourmastersam/studio` `develop` at `6fdb1d0688d51435e5f94d4376e30b6b16ff1762`.

Studio currently constructs embedded MySQL with `new MySQLLikeDriver(queryable, "")`. This produces broad metadata queries. `MySQLLikeDriver.schemas()` calls `this.batch()` with six statements, but `EmbedQueryable` does not implement the optional `batch` method, so `CommonSQLImplement.batch()` currently sends six sequential `query` envelopes. SchemaProvider then sends `SELECT DATABASE() AS db` separately.

The Pelican plugin currently forces `mode=probe` in PHP and JavaScript, accepts only `SELECT 1`, rejects every transaction, validates only the fixed diagnostic result shape, and executes a hard-coded `SELECT 1` through request-local PDO.

Existing baselines pass: Studio 232 Jest tests across 16 suites plus TypeScript typecheck; plugin 37 PHPUnit tests with 107 assertions plus 16 Node tests.

## Selected Approach

Use deterministic, server-owned operation classification. Studio will send its existing SQL, but Pelican will compare each statement to canonical SQL generated from the authorized `App\Models\Database` record and convert a match into an internal allowed-operation enum. The executor receives enums rather than browser SQL and independently regenerates the SQL it executes.

This is preferred over a SQL parser because the permitted language contains only eight fixed operations, so a parser adds dependency and normalization risk without enabling useful behavior. It is preferred over replacing Studio schema discovery with a new JSON metadata API because that would duplicate Studio's schema model and create a larger fork.

## Embed Configuration

The normal iframe URL is:

```text
https://studio.greyharbour.net/embed/mysql?channel=<nonce>&database=<selected-database-name>
```

The controller builds it with RFC 3986 encoding and an explicit `&` separator. The URL contains no host, username, password, connection string, Pelican token, or CSRF token.

Studio accepts the MySQL `database` parameter only when exactly one value exists. A valid value is non-empty, at most 64 Unicode code points, contains no ASCII control characters or Unicode replacement character, and survives URL decoding as a string. Missing, duplicate, empty, oversized, or malformed values show a safe embed configuration error and do not construct `MySQLLikeDriver`.

Other embedded drivers retain their current behavior and do not require `database`. Explicit `mode=probe` continues to render the diagnostic component and does not construct the schema-loading driver.

For normal embedded MySQL, Studio constructs:

```ts
new MySQLLikeDriver(queryable, selectedDatabase)
```

The parameter scopes SQL generation but grants no authority. Pelican always resolves the server and database route models, rechecks the user and `database.read`, verifies the server state, and checks the session channel binding before classifying any operation.

## Exact Allowed Operations

Let `D` be the authorized Pelican database name represented as a MySQL string literal by replacing each single quote with two single quotes and surrounding the result with single quotes. Pelican accepts the exact Studio-generated strings below and executes canonical copies generated from the database model:

1. Diagnostic query: `SELECT 1` with the existing diagnostic-only whitespace, case, and single-semicolon normalization.
2. Current database: `SELECT DATABASE() AS db`.
3. Schema: `SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = D`.
4. Tables: `SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, DATA_LENGTH, INDEX_LENGTH FROM information_schema.tables WHERE TABLE_SCHEMA = D`.
5. Columns: `SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, EXTRA, COLUMN_KEY, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE TABLE_SCHEMA = D`.
6. Constraints: `SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.table_constraints WHERE TABLE_SCHEMA = D AND CONSTRAINT_TYPE IN ('PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY')`.
7. Constraint columns: `SELECT CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.key_column_usage WHERE TABLE_SCHEMA = D`.
8. Triggers: `SELECT * from information_schema.triggers WHERE TRIGGER_SCHEMA = D`.

The normal schema bootstrap batch must contain operations 3 through 8 exactly once, in that order, with no additional statements. The parent validates the envelope shape; the backend repeats full validation and classifies the entire array before opening a database connection. An invalid member rejects the whole request and executes nothing.

`EmbedQueryable.batch()` will use the already-hardened `transaction` postMessage envelope. This is a transport batch, not a SQL `BEGIN`/`COMMIT` transaction. Pelican executes the six read-only statements sequentially on one request-local PDO connection and returns six result sets in input order. No SQL transaction commands are issued.

Exact single metadata queries may also be classified for compatibility with refresh behavior, but no query outside the eight operations above is accepted. In particular, prefixes such as `SELECT`, alternate predicates, comments, reordered clauses, another schema literal, `USE`, and all data or write statements fail closed.

## Pelican Components

`SchemaBootstrapPolicy` owns canonical SQL generation and classification. It returns `AllowedQuery` enum values and never returns browser SQL. Batch classification returns the exact six enum values only after every raw statement and its order match.

`QueryExecutor` evolves to execute one `AllowedQuery` or the exact schema-bootstrap enum batch for an authorized Database model. `MariaDbExecutor` creates one request-local connection with the existing selected credentials, three-second connection timeout, MariaDB statement timeout, disabled persistence, disabled multi-statements, and `utf8mb4`. It maps enum values to fixed executor-owned SQL templates and binds the authorized database name as a PDO parameter for metadata predicates; it never executes the raw request string or the comparison-only SQL literal received from Studio.

`DatabaseResultSerializer` converts PDO results to Studio `DatabaseResultSet` values. It uses `PDOStatement::getColumnMeta()` where available and falls back to returned row keys. Header names and display names are strings; `originalType` is the PDO native type or `null`; render hints map text to 1, integer to 2, real/decimal to 3, and binary/blob to 4. Rows contain only JSON-safe scalars or `null`; non-finite numbers, objects, resources, and unsupported values fail safely. Integers outside JavaScript's safe range are serialized as decimal strings. SELECT stats use `rowsAffected: 0`, `rowsRead: count(rows)`, `rowsWritten: null`, and measured nonnegative `queryDurationMs`. No `lastInsertRowid` is emitted.

Transactions return `DatabaseResultSet[]` in statement order. Results are copied through the browser bridge only after generic structural validation matching Studio's current runtime validator plus JSON-safe scalar checks for every row value. The bridge preserves exact origin, iframe `contentWindow`, channel, safe integer ID, duplicate-inflight suppression, reset/disposal behavior, exact response type, and safe generic errors.

The query endpoint accepts explicit `type: query` plus `statement`, or `type: transaction` plus `statements`. It rejects ambiguous/mixed shapes. Every request repeats ViewerAccess and ViewerContext checks. Logs retain only user/server/database IDs, operation class, outcome, result count, and duration; they omit SQL, channels, credentials, exception text, and row data.

## Studio Components

The embed page parses one database parameter for normal MySQL and passes it to `createDatabaseDriver`. `createDatabaseDriver` remains unchanged for non-MySQL drivers. The probe route remains selected only by one exact `mode=probe` value.

`EmbedQueryable` adds `batch(stmts)` and routes it through the existing `IframeConnection.transaction()` or Electron transaction implementation. The postMessage protocol and response validator already support transaction result arrays; their security checks remain unchanged.

The current MySQL driver SQL strings remain the source of truth. Tests pin the selected-database strings so drift is visible and requires an intentional matching policy update.

## Error Handling

Malformed embed configuration shows a generic configuration error without initializing Studio. Unsupported query and batch shapes return HTTP 422 with a generic policy error. Authorization and context failures retain their current 401/403/404 behavior. Connection, PDO metadata, serialization, and execution errors return HTTP 503 with `Database query failed.` and never expose exception text.

The browser bridge converts backend failures into a generic error response with the original request identity. Timeouts and page lifecycle cleanup retain the existing behavior.

## Tests

Studio tests cover exact database parsing, duplicate/malformed rejection, selected-database driver construction, canonical MySQL bootstrap SQL, normal versus probe route selection, `batch` use of the transaction envelope, and unchanged origin/window/channel/runtime-result validation. Existing non-MySQL embed tests continue to pass.

Plugin tests cover credential-free iframe parameters, no forced probe mode, current-database acceptance, the exact six-statement batch, other-schema and mutated-SQL rejection, arbitrary reads and writes, all-or-none classification, result order, generic result serialization, JSON-safe values, connection errors, credential secrecy, and all existing authorization/context controls. Connector/PDO doubles provide deterministic coverage without production access.

Manual verification uses a disposable MariaDB instance with one empty database and one database containing a small table. The expected milestone is that both viewers leave Connecting and show the normal Studio interface, and the populated database shows its table and columns. Table-row browsing remains unsupported and must return a controlled policy error.

## Delivery

The plugin version becomes 0.2.0 with an allowlisted ZIP and SHA-256 sidecar. README and verification documents describe the exact metadata-only policy and limitations. Studio embedding documentation describes the required single `database` parameter and retained probe mode.

The Studio Worker and plugin repository are changed and tested first. Production deployment and Panel import are reported separately from local verification; no production database result is claimed without an observed end-to-end run.

Timed sessions, Extend/Close controls, server-side expiry/revocation, and concurrency limits remain the next security phase. Safe read-only table browsing remains the next data phase.
