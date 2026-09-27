<?php

namespace GreyHarbour\DatabaseViewer\Services;

use App\Models\Database;

interface QueryExecutor
{
    /** Execute the fixed SELECT 1, verify the result, and return elapsed milliseconds. */
    public function execute(Database $database): float;
}
