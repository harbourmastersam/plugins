<?php

namespace GreyHarbour\DatabaseViewer\Tests;

use App\Models\Database;
use GreyHarbour\DatabaseViewer\Enums\AllowedQuery;
use GreyHarbour\DatabaseViewer\Enums\SqlAccessMode;
use GreyHarbour\DatabaseViewer\Exceptions\DatabaseStatementException;
use GreyHarbour\DatabaseViewer\Services\BrokerLimits;
use GreyHarbour\DatabaseViewer\Services\DatabaseResultSerializer;
use GreyHarbour\DatabaseViewer\Services\MariaDbExecutor;
use Illuminate\Database\Connectors\MySqlConnector;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MariaDbExecutorTest extends TestCase
{
    private function database(): Database
    {
        $database = new class extends Database
        {
            public array $testValues = [];

            public function getAttribute($key)
            {
                return array_key_exists($key, $this->testValues)
                    ? $this->testValues[$key]
                    : parent::getAttribute($key);
            }
        };
        $database->testValues = [
            'database' => "tenant's_data",
            'username' => 'tenant_user',
            'password' => 'tenant_password',
            'host' => (object) ['host' => '127.0.0.1', 'port' => 3306],
        ];

        return $database;
    }

    private function statement(array $rows = []): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $statement->method('fetch')->with(PDO::FETCH_ASSOC)->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
        $statement->method('columnCount')->willReturn($rows === [] ? 0 : count($rows[0]));
        $statement->method('getColumnMeta')->willReturn(false);

        return $statement;
    }

    public function test_standalone_operations_use_fixed_sql_and_selected_credentials(): void
    {
        $database = $this->database();
        $statements = [$this->statement([['1' => 1]]), $this->statement([['db' => "tenant's_data"]])];
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(2))->method('prepare')->willReturnCallback(function (string $sql) use (&$statements) {
            $this->assertContains($sql, ['SELECT 1', 'SELECT DATABASE() AS db']);

            return array_shift($statements);
        });
        $connector = $this->createMock(MySqlConnector::class);
        $connector->expects($this->exactly(2))->method('connect')->with($this->callback(function (array $config) use ($database): bool {
            return $config['host'] === '127.0.0.1'
                && $config['port'] === 3306
                && $config['database'] === $database->database
                && $config['username'] === $database->username
                && $config['password'] === $database->password
                && $config['charset'] === 'utf8mb4'
                && $config['options'][PDO::ATTR_TIMEOUT] === BrokerLimits::CONNECTION_TIMEOUT_SECONDS
                && $config['options'][PDO::ATTR_PERSISTENT] === false
                && $config['options'][PDO::MYSQL_ATTR_MULTI_STATEMENTS] === false
                && $config['options'][PDO::MYSQL_ATTR_INIT_COMMAND] === 'SET SESSION max_statement_time='.BrokerLimits::STATEMENT_TIMEOUT_SECONDS;
        }))->willReturn($pdo);

        $executor = new MariaDbExecutor($connector, new DatabaseResultSerializer());
        $this->assertSame([['1' => 1]], $executor->execute($database, AllowedQuery::Diagnostic)['rows']);
        $this->assertSame([['db' => "tenant's_data"]], $executor->execute($database, AllowedQuery::CurrentDatabase)['rows']);
    }

    public function test_schema_batch_uses_one_connection_fixed_prepared_sql_and_bound_database(): void
    {
        $database = $this->database();
        $expectedSql = [
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
            'SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, DATA_LENGTH, INDEX_LENGTH FROM information_schema.tables WHERE TABLE_SCHEMA = ?',
            'SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, EXTRA, COLUMN_KEY, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE TABLE_SCHEMA = ?',
            "SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.table_constraints WHERE TABLE_SCHEMA = ? AND CONSTRAINT_TYPE IN ('PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY')",
            'SELECT CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.key_column_usage WHERE TABLE_SCHEMA = ?',
            'SELECT * from information_schema.triggers WHERE TRIGGER_SCHEMA = ?',
        ];
        $prepared = [];
        foreach (range(1, 6) as $index) {
            $statement = $this->createMock(PDOStatement::class);
            $statement->expects($this->once())->method('execute')->with(["tenant's_data"])->willReturn(true);
            $statement->method('fetch')->with(PDO::FETCH_ASSOC)->willReturnOnConsecutiveCalls(['result' => $index], false);
            $statement->method('columnCount')->willReturn(1);
            $statement->method('getColumnMeta')->willReturn(false);
            $prepared[] = $statement;
        }
        $pdo = $this->createMock(PDO::class);
        $prepareIndex = 0;
        $pdo->expects($this->exactly(6))->method('prepare')->willReturnCallback(function (string $sql) use (&$prepareIndex, $expectedSql, &$prepared) {
            $this->assertSame($expectedSql[$prepareIndex], $sql);

            return $prepared[$prepareIndex++];
        });
        $connector = $this->createMock(MySqlConnector::class);
        $connector->expects($this->once())->method('connect')->willReturn($pdo);

        $results = (new MariaDbExecutor($connector, new DatabaseResultSerializer()))->executeBatch($database, [
            AllowedQuery::Schema,
            AllowedQuery::Tables,
            AllowedQuery::Columns,
            AllowedQuery::Constraints,
            AllowedQuery::ConstraintColumns,
            AllowedQuery::Triggers,
        ]);

        $this->assertSame([1, 2, 3, 4, 5, 6], array_map(fn (array $result) => $result['rows'][0]['result'], $results));
    }

    public function test_executor_rejects_wrong_operation_shapes_before_connecting(): void
    {
        $connector = $this->createMock(MySqlConnector::class);
        $connector->expects($this->never())->method('connect');
        $executor = new MariaDbExecutor($connector, new DatabaseResultSerializer());

        foreach ([AllowedQuery::Schema, AllowedQuery::Tables, AllowedQuery::Triggers] as $operation) {
            try {
                $executor->execute($this->database(), $operation);
                $this->fail('Schema operation was accepted as a standalone query.');
            } catch (RuntimeException) {
            }
        }

        $this->expectException(RuntimeException::class);
        $executor->executeBatch($this->database(), [AllowedQuery::Schema]);
    }

    public function test_prepare_or_execute_failure_aborts_without_returning_partial_results(): void
    {
        $prepareFailure = $this->createStub(PDO::class);
        $prepareFailure->method('prepare')->willReturn(false);
        $executeFailure = $this->createStub(PDOStatement::class);
        $executeFailure->method('execute')->willReturn(false);
        $executePdo = $this->createStub(PDO::class);
        $executePdo->method('prepare')->willReturn($executeFailure);
        $connector = $this->createMock(MySqlConnector::class);
        $connector->expects($this->exactly(2))->method('connect')->willReturnOnConsecutiveCalls($prepareFailure, $executePdo);
        $executor = new MariaDbExecutor($connector, new DatabaseResultSerializer());

        foreach ([0, 1] as $attempt) {
            try {
                $executor->execute($this->database(), AllowedQuery::Diagnostic);
                $this->fail("Failure $attempt returned a result.");
            } catch (RuntimeException) {
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_connector_and_serializer_failures_propagate_without_a_result(): void
    {
        $connector = $this->createStub(MySqlConnector::class);
        $connector->method('connect')->willThrowException(new RuntimeException('connect failed'));
        $this->expectException(RuntimeException::class);
        (new MariaDbExecutor($connector, new DatabaseResultSerializer()))->execute($this->database(), AllowedQuery::Diagnostic);
    }

    public function test_full_statements_support_reads_writes_ddl_and_explicit_transaction_control_on_one_connection(): void
    {
        $sql = ['SELECT 1', 'INSERT INTO widgets VALUES (1)', 'UPDATE widgets SET id = 2', 'DELETE FROM widgets', 'CREATE TABLE example (id INT)', 'START TRANSACTION', 'ROLLBACK'];
        $prepared = [
            $this->statement([['1' => 1]]),
            $this->statement(),
            $this->statement(),
            $this->statement(),
            $this->statement(),
            $this->statement(),
            $this->statement(),
        ];
        $pdo = $this->createMock(PDO::class);
        $index = 0;
        $pdo->expects($this->exactly(count($sql)))->method('prepare')->willReturnCallback(function (string $statement) use (&$index, $sql, $prepared) {
            $this->assertSame($sql[$index], $statement);

            return $prepared[$index++];
        });
        $pdo->expects($this->never())->method('beginTransaction');
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->never())->method('rollBack');
        $connector = $this->createMock(MySqlConnector::class);
        $connector->expects($this->once())->method('connect')->willReturn($pdo);

        $results = (new MariaDbExecutor($connector, new DatabaseResultSerializer()))
            ->executeStatements($this->database(), $sql, SqlAccessMode::Full);

        $this->assertCount(count($sql), $results);
        $this->assertSame([['1' => 1]], $results[0]['rows']);
    }

    public function test_full_command_reports_affected_rows_and_insert_id(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('execute')->with([])->willReturn(true);
        $statement->method('columnCount')->willReturn(0);
        $statement->method('rowCount')->willReturn(2);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->with('INSERT INTO widgets VALUES (1), (2)')->willReturn($statement);
        $pdo->method('lastInsertId')->willReturn('42');
        $connector = $this->createStub(MySqlConnector::class);
        $connector->method('connect')->willReturn($pdo);

        $result = (new MariaDbExecutor($connector, new DatabaseResultSerializer()))
            ->executeStatement($this->database(), 'INSERT INTO widgets VALUES (1), (2)', SqlAccessMode::Full);

        $this->assertSame(2, $result['stat']['rowsAffected']);
        $this->assertSame(2, $result['stat']['rowsWritten']);
        $this->assertSame(42, $result['lastInsertRowid']);
    }

    public function test_read_only_statement_uses_a_read_only_transaction_and_always_rolls_back(): void
    {
        $statement = $this->statement([['value' => 1]]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('exec')->with('SET TRANSACTION READ ONLY')->willReturn(0);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('prepare')->with('SELECT value FROM widgets')->willReturn($statement);
        $pdo->expects($this->once())->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $pdo->expects($this->never())->method('commit');
        $connector = $this->createStub(MySqlConnector::class);
        $connector->method('connect')->willReturn($pdo);

        $result = (new MariaDbExecutor($connector, new DatabaseResultSerializer()))
            ->executeStatement($this->database(), 'SELECT value FROM widgets', SqlAccessMode::ReadOnly);

        $this->assertSame([['value' => 1]], $result['rows']);
    }

    public function test_read_only_database_rejection_is_sanitized_and_rolled_back(): void
    {
        $submitted = 'SELECT writable_function()';
        $failure = new PDOException("SQLSTATE[25006]: Read only SQL transaction: 1792 $submitted");
        $failure->errorInfo = ['25006', 1792, "Cannot execute $submitted in a READ ONLY transaction\npassword=secret"];
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willThrowException($failure);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $connector = $this->createStub(MySqlConnector::class);
        $connector->method('connect')->willReturn($pdo);

        try {
            (new MariaDbExecutor($connector, new DatabaseResultSerializer()))
                ->executeStatement($this->database(), $submitted, SqlAccessMode::ReadOnly);
            $this->fail('Read-only database failure was returned as a result.');
        } catch (DatabaseStatementException $exception) {
            $this->assertStringContainsString('25006', $exception->diagnostic());
            $this->assertStringNotContainsString($submitted, $exception->diagnostic());
            $this->assertStringNotContainsString('secret', $exception->diagnostic());
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $exception->diagnostic());
        }
    }

    public function test_full_batch_stops_on_first_statement_error_without_returning_partial_results(): void
    {
        $first = $this->statement();
        $failure = new PDOException('syntax failure');
        $failure->errorInfo = ['42000', 1064, 'Syntax failure'];
        $second = $this->createStub(PDOStatement::class);
        $second->method('execute')->willThrowException($failure);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->exactly(2))->method('prepare')->willReturnOnConsecutiveCalls($first, $second);
        $connector = $this->createStub(MySqlConnector::class);
        $connector->method('connect')->willReturn($pdo);

        $this->expectException(DatabaseStatementException::class);
        (new MariaDbExecutor($connector, new DatabaseResultSerializer()))->executeStatements(
            $this->database(),
            ['INSERT INTO widgets VALUES (1)', 'BROKEN SQL', 'INSERT INTO widgets VALUES (2)'],
            SqlAccessMode::Full,
        );
    }

    public function test_statement_diagnostics_remove_sensitive_content_and_are_bounded(): void
    {
        $statement = 'SELECT * FROM private_table';
        $failure = new PDOException('driver failure');
        $failure->errorInfo = [
            '42000',
            1064,
            "Bad SQL $statement mysql:host=db;password=hunter2 C:\\app\\secret.php /var/www/panel/file.php\x00".str_repeat('x', 3000),
        ];

        $diagnostic = DatabaseStatementException::fromPdo($failure, $statement)->diagnostic();

        $this->assertLessThanOrEqual(2048, strlen($diagnostic));
        $this->assertStringContainsString('42000', $diagnostic);
        $this->assertStringContainsString('1064', $diagnostic);
        foreach ([$statement, 'hunter2', 'mysql:host', 'secret.php', '/var/www', "\x00"] as $secret) {
            $this->assertStringNotContainsString($secret, $diagnostic);
        }
    }

    public function test_statement_diagnostics_redact_partial_sql_and_database_identity_fragments(): void
    {
        $failure = new PDOException('driver failure');
        $failure->errorInfo = [
            '42000',
            1064,
            "Syntax error near 'FROM private_table WHERE token = 123' for user 'tenant_user'@'db.internal'",
        ];

        $diagnostic = DatabaseStatementException::fromPdo(
            $failure,
            'SELECT secret_value FROM private_table WHERE token = 123',
        )->diagnostic();

        foreach (['private_table', 'token = 123', 'tenant_user', 'db.internal'] as $secret) {
            $this->assertStringNotContainsString($secret, $diagnostic);
        }
        $this->assertStringContainsString('Syntax error near [redacted]', $diagnostic);
    }
}
