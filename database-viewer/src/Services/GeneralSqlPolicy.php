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

        $tokens = $this->tokens($statement);
        if ($tokens === null || $tokens === []) {
            return false;
        }

        $topLevelSemicolons = array_keys(array_filter($tokens, fn (array $token): bool => $token['value'] === ';' && $token['depth'] === 0));
        if (count($topLevelSemicolons) > 1
            || ($topLevelSemicolons !== [] && $topLevelSemicolons[0] !== array_key_last($tokens))) {
            return false;
        }
        if ($topLevelSemicolons !== []) {
            array_pop($tokens);
        }

        $words = array_values(array_filter($tokens, fn (array $token): bool => $token['value'] !== ';'));
        if ($words === []) {
            return false;
        }
        $values = array_column($words, 'value');
        foreach (self::FORBIDDEN_SEQUENCES as $sequence) {
            if ($this->containsSequence($values, $sequence)) {
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

    /** @return list<array{value: string, depth: int}>|null */
    private function tokens(string $statement): ?array
    {
        $tokens = [];
        $length = strlen($statement);
        $depth = 0;
        for ($index = 0; $index < $length;) {
            $character = $statement[$index];
            if (ctype_space($character)) {
                $index++;
                continue;
            }
            if ($character === '#' || ($character === '-' && ($statement[$index + 1] ?? '') === '-'
                && (($next = $statement[$index + 2] ?? '') === '' || ctype_space($next)))) {
                $newline = strpos($statement, "\n", $index + 1);
                $index = $newline === false ? $length : $newline + 1;
                continue;
            }
            if ($character === '/' && ($statement[$index + 1] ?? '') === '*') {
                if (($statement[$index + 2] ?? '') === '!') {
                    return null;
                }
                $end = strpos($statement, '*/', $index + 2);
                if ($end === false) {
                    return null;
                }
                $index = $end + 2;
                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) {
                $nextIndex = $this->skipQuoted($statement, $index, $character);
                if ($nextIndex < 0) {
                    return null;
                }
                $tokens[] = ['value' => '?', 'depth' => $depth];
                $index = $nextIndex;
                continue;
            }
            if ($character === '(') {
                $tokens[] = ['value' => '?', 'depth' => $depth];
                $depth++;
                $index++;
                continue;
            }
            if ($character === ')') {
                if (--$depth < 0) {
                    return null;
                }
                $tokens[] = ['value' => '?', 'depth' => $depth];
                $index++;
                continue;
            }
            if ($character === ';') {
                $tokens[] = ['value' => ';', 'depth' => $depth];
                $index++;
                continue;
            }
            if (ctype_alpha($character) || $character === '_') {
                $start = $index++;
                while ($index < $length && (ctype_alnum($statement[$index]) || in_array($statement[$index], ['_', '$'], true))) {
                    $index++;
                }
                $tokens[] = ['value' => strtoupper(substr($statement, $start, $index - $start)), 'depth' => $depth];
                continue;
            }
            $tokens[] = ['value' => '?', 'depth' => $depth];
            $index++;
        }

        return $depth === 0 ? $tokens : null;
    }

    private function skipQuoted(string $statement, int $index, string $delimiter): int
    {
        $length = strlen($statement);
        for ($index++; $index < $length; $index++) {
            if ($statement[$index] === '\\' && $delimiter !== '`') {
                $index++;
                continue;
            }
            if ($statement[$index] !== $delimiter) {
                continue;
            }
            if (($statement[$index + 1] ?? '') === $delimiter) {
                $index++;
                continue;
            }

            return $index + 1;
        }

        return -1;
    }

    private function containsSequence(array $values, array $sequence): bool
    {
        $length = count($sequence);
        for ($index = 0; $index <= count($values) - $length; $index++) {
            if (array_slice($values, $index, $length) === $sequence) {
                return true;
            }
        }

        return false;
    }

}
