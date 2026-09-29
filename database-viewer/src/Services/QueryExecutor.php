<?php

namespace GreyHarbour\DatabaseViewer\Services;

use App\Models\Database;
use GreyHarbour\DatabaseViewer\Enums\AllowedQuery;
use GreyHarbour\DatabaseViewer\Enums\SqlAccessMode;

interface QueryExecutor
{
    public function execute(Database $database, AllowedQuery $operation): array;

    /**
     * @param  list<AllowedQuery>  $operations
     * @return list<array<string, mixed>>
     */
    public function executeBatch(Database $database, array $operations): array;

    public function executeStatement(Database $database, string $statement, SqlAccessMode $mode): array;

    /**
     * @param  list<string>  $statements
     * @return list<array<string, mixed>>
     */
    public function executeStatements(Database $database, array $statements, SqlAccessMode $mode): array;
}
