<?php

namespace GreyHarbour\DatabaseViewer\Services;

use App\Models\Database;
use GreyHarbour\DatabaseViewer\Enums\AllowedQuery;
use GreyHarbour\DatabaseViewer\Enums\SqlAccessMode;
use GreyHarbour\DatabaseViewer\Exceptions\DatabaseStatementException;
use Illuminate\Database\Connectors\MySqlConnector;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class MariaDbExecutor implements QueryExecutor
{
    private const SCHEMA_OPERATIONS = [
        AllowedQuery::Schema,
        AllowedQuery::Tables,
        AllowedQuery::Columns,
        AllowedQuery::Constraints,
        AllowedQuery::ConstraintColumns,
        AllowedQuery::Triggers,
    ];

    public function __construct(
        private MySqlConnector $connector,
        private DatabaseResultSerializer $serializer,
        private BrokerResponseGuard $responseGuard = new BrokerResponseGuard(),
    ) {}

    public function execute(Database $database, AllowedQuery $operation): array
    {
        if (!in_array($operation, [AllowedQuery::Diagnostic, AllowedQuery::CurrentDatabase], true)) {
            throw new RuntimeException('Operation is not permitted as a standalone query.');
        }

        $connection = $this->connect($database);

        return $this->executeOnConnection($connection, $database, $operation);
    }

    /**
     * @param  list<AllowedQuery>  $operations
     * @return list<array<string, mixed>>
     */
    public function executeBatch(Database $database, array $operations): array
    {
        if ($operations !== self::SCHEMA_OPERATIONS) {
            throw new RuntimeException('Schema bootstrap operation sequence is invalid.');
        }

        $connection = $this->connect($database);
        $results = [];
        foreach ($operations as $operation) {
            $results[] = $this->executeOnConnection($connection, $database, $operation);
        }

        return $results;
    }

    public function executeStatement(Database $database, string $statement, SqlAccessMode $mode): array
    {
        $connection = $this->connect($database);
        if ($mode === SqlAccessMode::Full) {
            return $this->executeGeneralOnConnection($connection, $statement);
        }

        if ($connection->exec('SET TRANSACTION READ ONLY') === false || !$connection->beginTransaction()) {
            throw new RuntimeException('Unable to start a read-only database transaction.');
        }
        try {
            return $this->executeGeneralOnConnection($connection, $statement);
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }
    }

    public function executeStatements(Database $database, array $statements, SqlAccessMode $mode): array
    {
        if ($mode !== SqlAccessMode::Full) {
            throw new RuntimeException('General SQL batches require full access.');
        }

        $connection = $this->connect($database);
        if (!$connection->beginTransaction()) {
            throw new RuntimeException('Unable to start database transaction.');
        }
        try {
            $results = [];
            foreach ($statements as $statement) {
                $results[] = $this->executeGeneralOnConnection($connection, $statement);
            }
            $this->responseGuard->encodeData($results);
            if (!$connection->commit()) {
                throw new RuntimeException('Unable to commit database transaction.');
            }

            return $results;
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                try {
                    $connection->rollBack();
                } catch (Throwable) {
                    // Preserve the statement/serialization/commit failure that triggered rollback.
                }
            }

            throw $exception;
        }
    }

    private function connect(Database $database): PDO
    {
        $host = $database->host;

        return $this->connector->connect([
            'host' => $host->host,
            'port' => $host->port,
            'database' => $database->database,
            'username' => $database->username,
            'password' => $database->password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [
                PDO::ATTR_TIMEOUT => BrokerLimits::CONNECTION_TIMEOUT_SECONDS,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION max_statement_time='.BrokerLimits::STATEMENT_TIMEOUT_SECONDS,
            ],
        ]);
    }

    private function executeOnConnection(PDO $connection, Database $database, AllowedQuery $operation): array
    {
        [$sql, $parameters] = $this->statement($database, $operation);
        $start = hrtime(true);
        $statement = $connection->prepare($sql);
        if ($statement === false || !$statement->execute($parameters)) {
            throw new RuntimeException('Database operation failed.');
        }

        return $this->serializer->serialize($statement, (hrtime(true) - $start) / 1_000_000);
    }

    private function executeGeneralOnConnection(PDO $connection, string $sql): array
    {
        $start = hrtime(true);
        try {
            $statement = $connection->prepare($sql);
            if ($statement === false || !$statement->execute([])) {
                throw new RuntimeException('Database operation failed.');
            }
        } catch (PDOException $exception) {
            throw DatabaseStatementException::fromPdo($exception, $sql);
        }

        return $this->serializer->serialize(
            $statement,
            (hrtime(true) - $start) / 1_000_000,
            $connection->lastInsertId(),
        );
    }

    /** @return array{string, list<string>} */
    private function statement(Database $database, AllowedQuery $operation): array
    {
        $scope = [(string) $database->database];

        return match ($operation) {
            AllowedQuery::Diagnostic => ['SELECT 1', []],
            AllowedQuery::CurrentDatabase => ['SELECT DATABASE() AS db', []],
            AllowedQuery::Schema => ['SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', $scope],
            AllowedQuery::Tables => ['SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, DATA_LENGTH, INDEX_LENGTH FROM information_schema.tables WHERE TABLE_SCHEMA = ?', $scope],
            AllowedQuery::Columns => ['SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, EXTRA, COLUMN_KEY, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE TABLE_SCHEMA = ?', $scope],
            AllowedQuery::Constraints => ["SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.table_constraints WHERE TABLE_SCHEMA = ? AND CONSTRAINT_TYPE IN ('PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY')", $scope],
            AllowedQuery::ConstraintColumns => ['SELECT CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.key_column_usage WHERE TABLE_SCHEMA = ?', $scope],
            AllowedQuery::Triggers => ['SELECT * from information_schema.triggers WHERE TRIGGER_SCHEMA = ?', $scope],
        };
    }
}
