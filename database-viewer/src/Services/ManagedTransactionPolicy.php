<?php

namespace GreyHarbour\DatabaseViewer\Services;

final class ManagedTransactionPolicy
{
    private const ALLOWED_ROOTS = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE'];

    private const FORBIDDEN_SEQUENCES = [
        ['INTO', 'OUTFILE'],
        ['INTO', 'DUMPFILE'],
        ['FOR', 'UPDATE'],
        ['LOCK', 'IN', 'SHARE', 'MODE'],
    ];

    private const NESTED_MUTATIONS = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME',
        'CALL', 'DO', 'SET', 'LOAD', 'GRANT', 'REVOKE', 'LOCK', 'UNLOCK', 'XA', 'PREPARE', 'EXECUTE',
    ];

    public function __construct(
        private SqlStatementInspector $inspector = new SqlStatementInspector(),
        private GeneralSqlPolicy $general = new GeneralSqlPolicy(),
    ) {}

    public function allowsStatement(string $statement): bool
    {
        if (!$this->general->validStatement($statement)) {
            return false;
        }
        $tokens = $this->inspector->tokens($statement);
        $root = $this->inspector->rootOperation($statement);
        if ($tokens === null || !in_array($root, self::ALLOWED_ROOTS, true)) {
            return false;
        }
        foreach (self::FORBIDDEN_SEQUENCES as $sequence) {
            if ($this->inspector->containsSequence($tokens, $sequence)) {
                return false;
            }
        }
        if ($tokens[0]['value'] === 'WITH') {
            foreach ($tokens as $token) {
                if ($token['depth'] > 0 && in_array($token['value'], self::NESTED_MUTATIONS, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function allowsBatch(array $statements): bool
    {
        if (!$this->general->validBatch($statements)) {
            return false;
        }
        foreach ($statements as $statement) {
            if (!$this->allowsStatement($statement)) {
                return false;
            }
        }

        return true;
    }
}
