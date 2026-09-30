<?php

namespace GreyHarbour\DatabaseViewer\Tests;

use GreyHarbour\DatabaseViewer\Services\ManagedTransactionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ManagedTransactionPolicyTest extends TestCase
{
    private ManagedTransactionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ManagedTransactionPolicy();
    }

    public static function acceptedStatements(): array
    {
        return array_map(fn (string $sql) => [$sql], [
            'SELECT * FROM widgets',
            "-- row insert\nINSERT INTO widgets (name) VALUES ('FOR UPDATE')",
            'UPDATE widgets SET enabled = 1 WHERE id = 2;',
            'DELETE FROM widgets WHERE id = 2',
            'REPLACE INTO widgets (id, name) VALUES (2, \'new\')',
            'WITH chosen AS (SELECT id FROM widgets WHERE enabled = 1) SELECT * FROM chosen',
            'WITH chosen AS (SELECT id FROM widgets) UPDATE widgets SET enabled = 0 WHERE id IN (SELECT id FROM chosen)',
        ]);
    }

    #[DataProvider('acceptedStatements')]
    public function test_transaction_compatible_statements_are_allowed(string $sql): void
    {
        $this->assertTrue($this->policy->allowsStatement($sql));
    }

    public static function rejectedStatements(): array
    {
        return array_map(fn (string $sql) => [$sql], [
            '',
            'SELECT 1; SELECT 2',
            "SELECT * FROM widgets INTO OUTFILE '/tmp/widgets'",
            "SELECT * FROM widgets INTO DUMPFILE '/tmp/widgets'",
            'SELECT * FROM widgets FOR UPDATE',
            'SELECT * FROM widgets LOCK IN SHARE MODE',
            'WITH changed AS (DELETE FROM widgets RETURNING id) SELECT * FROM changed',
            'WITH chosen AS (SELECT * FROM widgets FOR UPDATE) SELECT * FROM chosen',
            'CREATE TABLE widgets (id INT)',
            'ALTER TABLE widgets ADD COLUMN name TEXT',
            'DROP TABLE widgets',
            'TRUNCATE TABLE widgets',
            'RENAME TABLE widgets TO old_widgets',
            'START TRANSACTION',
            'BEGIN',
            'COMMIT',
            'ROLLBACK',
            'SAVEPOINT before_update',
            'RELEASE SAVEPOINT before_update',
            'SET autocommit = 0',
            'LOCK TABLES widgets WRITE',
            'UNLOCK TABLES',
            "XA START 'viewer'",
            'CALL mutate_widgets()',
            "PREPARE stmt FROM 'DELETE FROM widgets'",
            "LOAD DATA INFILE '/tmp/widgets' INTO TABLE widgets",
            'GRANT SELECT ON app.* TO viewer',
            'REVOKE SELECT ON app.* FROM viewer',
            'ANALYZE TABLE widgets',
            'CHECK TABLE widgets',
            'OPTIMIZE TABLE widgets',
            'REPAIR TABLE widgets',
            'FLUSH TABLES',
            'RESET MASTER',
            '/*!50000 DELETE FROM widgets */ SELECT 1',
            '/* unterminated SELECT 1',
            'SELECT (1',
        ]);
    }

    #[DataProvider('rejectedStatements')]
    public function test_non_atomic_or_ambiguous_statements_are_rejected(string $sql): void
    {
        $this->assertFalse($this->policy->allowsStatement($sql));
    }

    public function test_entire_batch_must_be_valid_before_it_is_allowed(): void
    {
        $this->assertTrue($this->policy->allowsBatch([
            'INSERT INTO widgets (id) VALUES (1)',
            'UPDATE widgets SET enabled = 1 WHERE id = 1',
        ]));
        $this->assertFalse($this->policy->allowsBatch([
            'INSERT INTO widgets (id) VALUES (1)',
            'CREATE TABLE audit (id INT)',
            'DELETE FROM widgets WHERE id = 1',
        ]));
        $this->assertFalse($this->policy->allowsBatch([]));
        $this->assertFalse($this->policy->allowsBatch(['SELECT 1', 2]));
    }
}
