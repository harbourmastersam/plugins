<?php

namespace GreyHarbour\DatabaseViewer\Services;

use App\Models\Database;
use GreyHarbour\DatabaseViewer\Enums\AllowedQuery;

interface QueryExecutor
{
    public function execute(Database $database, AllowedQuery $operation = AllowedQuery::Diagnostic): array;

    /**
     * @param  list<AllowedQuery>  $operations
     * @return list<array<string, mixed>>
     */
    public function executeBatch(Database $database, array $operations): array;
}
