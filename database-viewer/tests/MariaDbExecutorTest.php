<?php

namespace GreyHarbour\DatabaseViewer\Tests;

use App\Models\Database;
use GreyHarbour\DatabaseViewer\Enums\AllowedQuery;
use GreyHarbour\DatabaseViewer\Services\BrokerLimits;
use GreyHarbour\DatabaseViewer\Services\DatabaseResultSerializer;
use GreyHarbour\DatabaseViewer\Services\MariaDbExecutor;
use Illuminate\Database\Connectors\MySqlConnector;
use PDO;
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
}
