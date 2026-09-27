<?php

namespace GreyHarbour\DatabaseViewer\Services;

use App\Models\Database;
use Illuminate\Database\Connectors\MySqlConnector;
use PDO;
use RuntimeException;

class MariaDbExecutor implements QueryExecutor
{
    public function __construct(private MySqlConnector $connector) {}

    public function execute(Database $database): float
    {
        $start = hrtime(true);
        $connection = null;
        $statement = null;
        try {
            $host = $database->host;
            // DatabaseHost::buildConnection uses management credentials, not this database user.
            // Model casts decrypt only here, into a request-local connector configuration.
            $connection = $this->connector->connect([
                'host' => $host->host,
                'port' => $host->port,
                'database' => $database->database,
                'username' => $database->username,
                'password' => $database->password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'options' => [
                    PDO::ATTR_TIMEOUT => 3,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
                    // MariaDB session-local setting; does not modify schema or stored data.
                    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION max_statement_time=3',
                ],
            ]);
            $statement = $connection->query('SELECT 1');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1 || count($rows[0]) !== 1 || !in_array($rows[0][1] ?? null, [1, '1'], true)) {
                throw new RuntimeException('Unexpected test query result.');
            }

            return (hrtime(true) - $start) / 1_000_000;
        } finally {
            $statement = null;
            $connection = null;
        }
    }
}
