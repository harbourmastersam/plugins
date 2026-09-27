# Database Viewer Schema Bootstrap Design

## Goal

Open the real embedded MySQL Studio interface for one Pelican-selected MariaDB database while permitting only the metadata reads required for Studio startup. Arbitrary SQL, table-row reads, writes, exports, dumps, database switching, and unrestricted `information_schema` access remain unavailable.

The existing `mode=probe` connection test remains available as a diagnostic path. The normal Pelican Database Viewer stops selecting it.

## Current Source Findings

The design is based on `harbourmastersam/plugins` `main` at `8f164434e4d269b945a19a0e699aa9375612628c` and `harbourmastersam/studio` `develop` at `6fdb1d0688d51435e5f94d4376e30b6b16ff1762`.

Studio currently constructs embedded MySQL with `new MySQLLikeDriver(queryable, "")`. This produces broad metadata queries. `MySQLLikeDriver.schemas()` calls `this.batch()` with six statements. When invoked through the normal `Studio` component, the driver's `this` is the Studio proxy; the proxy intercepts both `batch` and `transaction`, applies the before-query pipeline, and calls `target.transaction(beforePipeline.getStatments())`. `MySQLLikeDriver.transaction()` then delegates to `EmbedQueryable.transaction()`, which emits one `transaction` postMessage envelope containing all six statements. SchemaProvider sends `SELECT DATABASE() AS db` separately through the proxied query path.

This call path was verified with a disposable Jest component probe against the current source: one normal Studio invocation of `MySQLLikeDriver.schemas()` produced one transaction call with six statements and zero individual query calls. The permanent implementation regression test will preserve that exact assertion. `EmbedQueryable.batch()` is not required and will not be added in this phase.

The Pelican plugin currently forces `mode=probe` in PHP and JavaScript, accepts only `SELECT 1`, rejects every transaction, validates only the fixed diagnostic result shape, and executes a hard-coded `SELECT 1` through request-local PDO.

Existing baselines pass: Studio 232 Jest tests across 16 suites plus TypeScript typecheck; plugin 37 PHPUnit tests with 107 assertions plus 16 Node tests.

## Selected Approach

Use deterministic, server-owned operation classification. Studio will send its existing SQL, but Pelican will compare each statement to canonical SQL generated from the authorized `App\Models\Database` record and convert a match into an internal allowed-operation enum. The executor receives enums rather than browser SQL and independently regenerates the SQL it executes.

This is preferred over a SQL parser because the permitted language contains only eight fixed internal operations carried by three exact request shapes, so a parser adds dependency and normalization risk without enabling useful behavior. It is preferred over replacing Studio schema discovery with a new JSON metadata API because that would duplicate Studio's schema model and create a larger fork.

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

## Exact Permitted Request Shapes

The broker permits only these three request shapes:

1. A `query` request containing diagnostic `SELECT 1`, using the existing diagnostic-only whitespace, case, and single-semicolon normalization.
2. A `query` request containing exactly `SELECT DATABASE() AS db`.
3. A `transaction` request containing exactly the six canonical schema statements below, exactly once and in this order.

Let `D` be the authorized Pelican database name represented for comparison with Studio's generated SQL by replacing each single quote with two single quotes and surrounding the result with single quotes. The six required transaction statements are:

1. Schema: `SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = D`.
2. Tables: `SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, DATA_LENGTH, INDEX_LENGTH FROM information_schema.tables WHERE TABLE_SCHEMA = D`.
3. Columns: `SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, EXTRA, COLUMN_KEY, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE TABLE_SCHEMA = D`.
4. Constraints: `SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.table_constraints WHERE TABLE_SCHEMA = D AND CONSTRAINT_TYPE IN ('PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY')`.
5. Constraint columns: `SELECT CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.key_column_usage WHERE TABLE_SCHEMA = D`.
6. Triggers: `SELECT * from information_schema.triggers WHERE TRIGGER_SCHEMA = D`.

The parent validates the envelope shape; the backend repeats full validation and classifies the entire six-statement array before opening a database connection. An invalid member, missing member, reordered member, duplicate member, or extra member rejects the whole request and executes nothing.

The existing Studio proxy and `EmbedQueryable.transaction()` path already produce the required transaction postMessage envelope, so no `EmbedQueryable.batch()` method is added. This is a transport batch, not a SQL `BEGIN`/`COMMIT` transaction. Pelican executes the six read-only operations sequentially on one request-local PDO connection and returns six result sets in input order. No SQL transaction commands are issued.

Standalone metadata statements are not accepted in this phase. No request outside the three shapes above is accepted. In particular, prefixes such as `SELECT`, alternate predicates, comments, reordered clauses, another schema literal, `USE`, and all data or write statements fail closed. If later observed Studio behavior needs a standalone metadata statement, that statement requires a separate intentional policy change and regression test.

## Pelican Components

`SchemaBootstrapPolicy` owns canonical SQL generation and classification. It returns internal `AllowedQuery` enum values and never returns browser SQL. Standalone classification can return only the diagnostic or current-database enum. Batch classification returns the exact six schema enums only after every raw statement and its order match.

`QueryExecutor` evolves to execute one `AllowedQuery` or the exact schema-bootstrap enum batch for an authorized Database model. `MariaDbExecutor` creates one request-local connection with the existing selected credentials, three-second connection timeout, MariaDB statement timeout, disabled persistence, disabled multi-statements, and `utf8mb4`. It maps enum values to fixed executor-owned SQL templates and binds the authorized database name as a PDO parameter for metadata predicates; it never executes the raw request string or the comparison-only SQL literal received from Studio.

`DatabaseResultSerializer` converts PDO results to Studio `DatabaseResultSet` values. It uses `PDOStatement::getColumnMeta()` where available and falls back to returned row keys. Header names and display names are strings; `originalType` is the PDO native type or `null`; render hints map text to 1, integer to 2, real/decimal to 3, and binary/blob to 4. Rows contain only JSON-safe scalars or `null`; non-finite numbers, objects, resources, and unsupported values fail safely. Integers outside JavaScript's safe range are serialized as decimal strings. SELECT stats use `rowsAffected: 0`, `rowsRead: count(rows)`, `rowsWritten: null`, and measured nonnegative `queryDurationMs`. No `lastInsertRowid` is emitted.

Transactions return `DatabaseResultSet[]` in statement order. Results are copied through the browser bridge only after generic structural validation matching Studio's current runtime validator plus JSON-safe scalar checks for every row value. The bridge preserves exact origin, iframe `contentWindow`, channel, safe integer ID, duplicate-inflight suppression, reset/disposal behavior, exact response type, and safe generic errors.

The query endpoint accepts explicit `type: query` plus `statement`, or `type: transaction` plus `statements`. It rejects ambiguous/mixed shapes. Every request repeats ViewerAccess and ViewerContext checks. Logs retain only user/server/database IDs, operation class, outcome, result count, and duration; they omit SQL, channels, credentials, exception text, and row data.

## Resource Boundaries

One central `BrokerLimits` definition owns these constants:

- schema-bootstrap statement count: exactly 6;
- maximum UTF-8 byte length of any statement: 2 KiB (2,048 bytes);
- maximum raw broker request body: 16 KiB (16,384 bytes);
- maximum serialized successful response: 5 MiB (5,242,880 bytes);
- database connection timeout: 3 seconds, preserving the current PDO setting;
- MariaDB per-statement timeout: 3 seconds, preserving the current session setting.

The endpoint rejects an oversized request body, oversized statement, wrong statement count, and invalid envelope before opening a database connection where practical. After executing into an internal complete result array, it serializes the entire success response and checks its byte size before returning it. A response over 5 MiB is discarded and returns the same generic database failure as other execution failures; it never returns a partial schema or includes result contents in the error. Tests pin every limit at and immediately above its boundary.

## Permission Boundary

The schema-bootstrap phase continues to require Pelican's existing `database.read` permission. That decision authorizes only opening this metadata-only viewer and reading the selected database's schema metadata.

It does not establish authorization for future database contents or modification capabilities. Before table-row browsing, the plugin design must evaluate a dedicated permission such as `database-viewer.read`. Before any modification feature, it must independently evaluate a permission such as `database-viewer.write`. Neither permission is added in this phase unless Pelican's plugin architecture proves it necessary for the current metadata capability.

## Studio Components

The embed page parses one database parameter for normal MySQL and passes it to `createDatabaseDriver`. `createDatabaseDriver` remains unchanged for non-MySQL drivers. The probe route remains selected only by one exact `mode=probe` value.

The existing Studio proxy continues routing `batch` through `target.transaction()`, and the existing `EmbedQueryable.transaction()` continues emitting the transaction envelope. The postMessage protocol and response validator already support transaction result arrays; their security checks remain unchanged. No `EmbedQueryable.batch()` method is added.

The current MySQL driver SQL strings remain the source of truth. Tests pin the selected-database strings so drift is visible and requires an intentional matching policy update.

## Error Handling

Malformed embed configuration shows a generic configuration error without initializing Studio. Unsupported query and batch shapes return HTTP 422 with a generic policy error. Authorization and context failures retain their current 401/403/404 behavior. Connection, PDO metadata, serialization, and execution errors return HTTP 503 with `Database query failed.` and never expose exception text.

The browser bridge converts backend failures into a generic error response with the original request identity. Timeouts and page lifecycle cleanup retain the existing behavior.

## Tests

Studio tests cover exact database parsing, duplicate/malformed rejection, selected-database driver construction, canonical MySQL bootstrap SQL, normal versus probe route selection, and unchanged origin/window/channel/runtime-result validation. A component regression invokes `MySQLLikeDriver.schemas()` through normal `Studio` and asserts one transaction envelope with six statements and zero individual query envelopes. Existing non-MySQL embed tests continue to pass.

Plugin tests cover credential-free iframe parameters, no forced probe mode, current-database acceptance, the exact six-statement batch, standalone metadata rejection, other-schema and mutated-SQL rejection, arbitrary reads and writes, all-or-none classification, result order, generic result serialization, JSON-safe values, connection errors, credential secrecy, all resource-limit boundaries, and all existing authorization/context controls. Connector/PDO doubles provide deterministic coverage without production access.

Manual verification uses a disposable MariaDB instance with one empty database and one database containing a small table. The expected milestone is that both viewers leave Connecting and show the normal Studio interface, and the populated database shows its table and columns. Table-row browsing remains unsupported and must return a controlled policy error.

## Delivery

The plugin version becomes 0.2.0 with an allowlisted ZIP and SHA-256 sidecar. README and verification documents describe the exact metadata-only policy and limitations. Studio embedding documentation describes the required single `database` parameter and retained probe mode.

The Studio Worker and plugin repository are changed and tested first. Production deployment and Panel import are reported separately from local verification; no production database result is claimed without an observed end-to-end run.

## Phase Sequence

This phase ends when schema bootstrap makes the normal embedded Studio UI load and display the authorized database's schema, tables, and columns. It does not add table-row access.

The next security phase adds approximately 15-minute viewer sessions, server-side expiry, Extend, Close, revocation, and concurrency/session tests. The next data phase evaluates a dedicated read permission and adds safe read-only table browsing. General query-editor access and any separately permissioned write capability remain later phases.
