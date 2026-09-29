<?php

namespace GreyHarbour\DatabaseViewer\Tests;

use GreyHarbour\DatabaseViewer\Services\BrokerLimits;
use GreyHarbour\DatabaseViewer\Services\GeneralSqlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GeneralSqlPolicyTest extends TestCase
{
    private GeneralSqlPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new GeneralSqlPolicy();
    }

    public static function readStatements(): array
    {
        return array_map(fn (string $sql) => [$sql], [
            'SELECT * FROM users',
            "-- inspect users\nSELECT * FROM users;",
            "# inspect users\nSHOW TABLES",
            '/* ordinary comment */ DESCRIBE users',
            'DESC `select`',
            'EXPLAIN UPDATE users SET active = 1',
            "WITH active AS (SELECT * FROM users WHERE note = ';') SELECT * FROM active",
            "SELECT ';' AS semicolon, 'FOR UPDATE' AS text",
            'SELECT `odd;name` FROM `rows`',
        ]);
    }

    #[DataProvider('readStatements')]
    public function test_documented_read_statements_are_allowed(string $statement): void
    {
        $this->assertTrue($this->policy->allowsReadOnly($statement));
    }

    public static function rejectedReadStatements(): array
    {
        return array_map(fn (string $sql) => [$sql], [
            '',
            '   ',
            'SELECT 1; SELECT 2',
            'SELECT 1; 0',
            "SELECT 1; 'second statement'",
            'INSERT INTO users VALUES (1)',
            'UPDATE users SET active = 1',
            'DELETE FROM users',
            'CREATE TABLE x (id INT)',
            'CALL mutate_users()',
            "WITH changed AS (UPDATE users SET active = 1 RETURNING *) SELECT * FROM changed",
            "SELECT * FROM users INTO OUTFILE '/tmp/users'",
            "SELECT * FROM users INTO DUMPFILE '/tmp/users'",
            'SELECT * FROM users FOR UPDATE',
            'SELECT * FROM users LOCK IN SHARE MODE',
            '/*!50000 UPDATE users SET active = 1 */ SELECT 1',
            '/* unterminated SELECT 1',
        ]);
    }

    #[DataProvider('rejectedReadStatements')]
    public function test_ambiguous_or_mutating_statements_are_rejected(string $statement): void
    {
        $this->assertFalse($this->policy->allowsReadOnly($statement));
    }

    public function test_statement_and_batch_limits_are_exact(): void
    {
        $exact = 'S'.str_repeat(' ', BrokerLimits::MAX_STATEMENT_BYTES - 1);
        $this->assertTrue($this->policy->validStatement($exact));
        $this->assertFalse($this->policy->validStatement($exact.' '));
        $this->assertFalse($this->policy->validStatement(''));

        $this->assertTrue($this->policy->validBatch(array_fill(0, BrokerLimits::MAX_BATCH_STATEMENTS, 'SELECT 1')));
        $this->assertFalse($this->policy->validBatch([]));
        $this->assertFalse($this->policy->validBatch(array_fill(0, BrokerLimits::MAX_BATCH_STATEMENTS + 1, 'SELECT 1')));
        $this->assertFalse($this->policy->validBatch(['SELECT 1', 2]));
        $this->assertFalse($this->policy->validBatch(['SELECT 1', '']));
    }
}
