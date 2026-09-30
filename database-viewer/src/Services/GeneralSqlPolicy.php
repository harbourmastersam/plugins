<?php

namespace GreyHarbour\DatabaseViewer\Services;

final class GeneralSqlPolicy
{
    private const READ_ROOTS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

    private const WRITE_WORDS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'CALL', 'DO', 'SET', 'LOAD', 'GRANT', 'REVOKE'];

    private const FORBIDDEN_SEQUENCES = [
        ['INTO', 'OUTFILE'],
        ['INTO', 'DUMPFILE'],
        ['FOR', 'UPDATE'],
        ['LOCK', 'IN', 'SHARE', 'MODE'],
    ];

    public function __construct(private SqlStatementInspector $inspector = new SqlStatementInspector()) {}

    public function validStatement(string $statement): bool
    {
        return trim($statement) !== '' && BrokerLimits::statementFits($statement);
    }

    public function validBatch(array $statements): bool
    {
        if (!array_is_list($statements)
            || count($statements) < 1
            || count($statements) > BrokerLimits::MAX_BATCH_STATEMENTS) {
            return false;
        }

        foreach ($statements as $statement) {
            if (!is_string($statement) || !$this->validStatement($statement)) {
                return false;
            }
        }

        return true;
    }

    public function allowsReadOnly(string $statement): bool
    {
        if (!$this->validStatement($statement)) {
            return false;
        }

        $tokens = $this->inspector->tokens($statement);
        if ($tokens === null || $tokens === []) {
            return false;
        }

        $words = $tokens;
        if ($words === []) {
            return false;
        }
        foreach (self::FORBIDDEN_SEQUENCES as $sequence) {
            if ($this->inspector->containsSequence($words, $sequence)) {
                return false;
            }
        }

        $root = $words[0]['value'];
        if (in_array($root, self::READ_ROOTS, true)) {
            return true;
        }
        if ($root !== 'WITH') {
            return false;
        }

        foreach ($words as $token) {
            if ($token['depth'] > 0 && in_array($token['value'], self::WRITE_WORDS, true)) {
                return false;
            }
        }
        foreach (array_slice($words, 1) as $token) {
            if ($token['depth'] === 0 && in_array($token['value'], ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true)) {
                return $token['value'] === 'SELECT';
            }
        }

        return false;
    }

}
