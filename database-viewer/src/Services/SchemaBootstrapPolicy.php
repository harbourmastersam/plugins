<?php

namespace GreyHarbour\DatabaseViewer\Services;

use App\Models\Database;
use GreyHarbour\DatabaseViewer\Enums\AllowedQuery;

final class SchemaBootstrapPolicy
{
    public function classifyQuery(Database $database, string $statement): ?AllowedQuery
    {
        if (!BrokerLimits::statementFits($statement)) {
            return null;
        }

        if (preg_match('/\A[ \t\r\n]*SELECT[ \t\r\n]+1[ \t\r\n]*;?[ \t\r\n]*\z/iD', $statement) === 1) {
            return AllowedQuery::Diagnostic;
        }

        return $statement === 'SELECT DATABASE() AS db'
            ? AllowedQuery::CurrentDatabase
            : null;
    }

    /** @return list<AllowedQuery>|null */
    public function classifyTransaction(Database $database, array $statements): ?array
    {
        if (!array_is_list($statements) || count($statements) !== BrokerLimits::SCHEMA_STATEMENT_COUNT) {
            return null;
        }

        $expected = $this->canonicalSchemaStatements((string) $database->database);
        foreach ($statements as $index => $statement) {
            if (!is_string($statement)
                || !BrokerLimits::statementFits($statement)
                || $statement !== $expected[$index]) {
                return null;
            }
        }

        return [
            AllowedQuery::Schema,
            AllowedQuery::Tables,
            AllowedQuery::Columns,
            AllowedQuery::Constraints,
            AllowedQuery::ConstraintColumns,
            AllowedQuery::Triggers,
        ];
    }

    /** @return list<string> */
    private function canonicalSchemaStatements(string $database): array
    {
        $literal = "'".str_replace("'", "''", $database)."'";

        return [
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = $literal",
            "SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, DATA_LENGTH, INDEX_LENGTH FROM information_schema.tables WHERE TABLE_SCHEMA = $literal",
            "SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, EXTRA, COLUMN_KEY, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE TABLE_SCHEMA = $literal",
            "SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.table_constraints WHERE TABLE_SCHEMA = $literal AND CONSTRAINT_TYPE IN ('PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY')",
            "SELECT CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.key_column_usage WHERE TABLE_SCHEMA = $literal",
            "SELECT * from information_schema.triggers WHERE TRIGGER_SCHEMA = $literal",
        ];
    }
}
