<?php

namespace GreyHarbour\DatabaseViewer\Tests;

use App\Models\Database;
use GreyHarbour\DatabaseViewer\Enums\AllowedQuery;
use GreyHarbour\DatabaseViewer\Services\BrokerLimits;
use GreyHarbour\DatabaseViewer\Services\SchemaBootstrapPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SchemaBootstrapPolicyTest extends TestCase
{
    private SchemaBootstrapPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SchemaBootstrapPolicy();
    }

    private function database(string $name = 's2_test'): Database
    {
        $database = new Database();
        $database->database = $name;

        return $database;
    }

    /** @return list<string> */
    private function statements(string $name = 's2_test'): array
    {
        $database = str_replace("'", "''", $name);

        return [
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$database'",
            "SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, DATA_LENGTH, INDEX_LENGTH FROM information_schema.tables WHERE TABLE_SCHEMA = '$database'",
            "SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, EXTRA, COLUMN_KEY, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE TABLE_SCHEMA = '$database'",
            "SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.table_constraints WHERE TABLE_SCHEMA = '$database' AND CONSTRAINT_TYPE IN ('PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY')",
            "SELECT CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.key_column_usage WHERE TABLE_SCHEMA = '$database'",
            "SELECT * from information_schema.triggers WHERE TRIGGER_SCHEMA = '$database'",
        ];
    }

    #[DataProvider('diagnosticQueries')]
    public function test_only_narrow_select_one_normalization_is_accepted(string $statement): void
    {
        $this->assertSame(AllowedQuery::Diagnostic, $this->policy->classifyQuery($this->database(), $statement));
    }

    public static function diagnosticQueries(): array
    {
        return [['SELECT 1'], [" select\t1;\r\n"], ["\nSeLeCt  1 \t"]];
    }

    public function test_current_database_query_must_match_exactly(): void
    {
        $database = $this->database();
        $this->assertSame(AllowedQuery::CurrentDatabase, $this->policy->classifyQuery($database, 'SELECT DATABASE() AS db'));
        $this->assertNull($this->policy->classifyQuery($database, 'select database() as db'));
        $this->assertNull($this->policy->classifyQuery($database, 'SELECT DATABASE() AS db;'));
    }

    public function test_exact_ordered_schema_batch_is_accepted_for_authorized_name(): void
    {
        $name = "客户'数据";
        $this->assertSame([
            AllowedQuery::Schema,
            AllowedQuery::Tables,
            AllowedQuery::Columns,
            AllowedQuery::Constraints,
            AllowedQuery::ConstraintColumns,
            AllowedQuery::Triggers,
        ], $this->policy->classifyTransaction($this->database($name), $this->statements($name)));
    }

    public function test_metadata_statements_are_never_accepted_standalone(): void
    {
        $database = $this->database();
        foreach ($this->statements() as $statement) {
            $this->assertNull($this->policy->classifyQuery($database, $statement));
        }
    }

    public static function invalidQueries(): array
    {
        return array_map(fn (string $statement) => [$statement], [
            'SELECT 2',
            'SELECT * FROM users',
            'UPDATE users SET admin = 1',
            'SELECT 1 -- comment',
            'SELECT 1;;',
            'USE other_database',
        ]);
    }

    #[DataProvider('invalidQueries')]
    public function test_arbitrary_queries_are_rejected(string $statement): void
    {
        $this->assertNull($this->policy->classifyQuery($this->database(), $statement));
    }

    public function test_any_batch_mutation_rejects_the_whole_transaction(): void
    {
        $database = $this->database();
        $statements = $this->statements();
        $variants = [
            array_slice($statements, 0, 5),
            [...$statements, $statements[0]],
            [$statements[1], $statements[0], ...array_slice($statements, 2)],
            [$statements[0], $statements[0], ...array_slice($statements, 2)],
            [...array_slice($statements, 0, 2), str_replace('TABLE_SCHEMA', 'table_schema', $statements[2]), ...array_slice($statements, 3)],
            [...array_slice($statements, 0, 4), str_replace("'s2_test'", "'other'", $statements[4]), $statements[5]],
            [...array_slice($statements, 0, 5), $statements[5].' -- comment'],
            [...array_slice($statements, 0, 5), 123],
        ];

        foreach ($variants as $variant) {
            $this->assertNull($this->policy->classifyTransaction($database, $variant));
        }
    }

    public function test_statement_byte_limit_is_exact_and_uses_utf8_bytes(): void
    {
        $this->assertSame(6, BrokerLimits::SCHEMA_STATEMENT_COUNT);
        $this->assertSame(2048, BrokerLimits::MAX_STATEMENT_BYTES);
        $this->assertTrue(BrokerLimits::statementFits(str_repeat('a', 2048)));
        $this->assertFalse(BrokerLimits::statementFits(str_repeat('a', 2049)));
        $this->assertTrue(BrokerLimits::statementFits(str_repeat('é', 1024)));
        $this->assertFalse(BrokerLimits::statementFits(str_repeat('é', 1025)));
    }

    public function test_all_resource_limits_are_centralized(): void
    {
        $this->assertSame(16384, BrokerLimits::MAX_REQUEST_BYTES);
        $this->assertSame(5242880, BrokerLimits::MAX_RESPONSE_BYTES);
        $this->assertSame(3, BrokerLimits::CONNECTION_TIMEOUT_SECONDS);
        $this->assertSame(3, BrokerLimits::STATEMENT_TIMEOUT_SECONDS);
    }
}
