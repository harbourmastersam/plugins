<?php

namespace GreyHarbour\DatabaseViewer\Services;

final class SqlStatementInspector
{
    private const OPERATION_WORDS = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE',
        'RENAME', 'START', 'BEGIN', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'SET', 'LOCK',
        'UNLOCK', 'XA', 'CALL', 'DO', 'PREPARE', 'EXECUTE', 'DEALLOCATE', 'LOAD', 'GRANT', 'REVOKE',
        'ANALYZE', 'CHECK', 'OPTIMIZE', 'REPAIR', 'FLUSH', 'RESET', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN',
    ];

    /** @return list<array{value: string, depth: int}>|null */
    public function tokens(string $statement): ?array
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

        if ($depth !== 0 || $tokens === []) {
            return null;
        }
        $topLevelSemicolons = array_keys(array_filter($tokens, fn (array $token): bool => $token['value'] === ';' && $token['depth'] === 0));
        if (count($topLevelSemicolons) > 1
            || ($topLevelSemicolons !== [] && $topLevelSemicolons[0] !== array_key_last($tokens))) {
            return null;
        }
        if ($topLevelSemicolons !== []) {
            array_pop($tokens);
        }

        return $tokens === [] ? null : $tokens;
    }

    public function rootOperation(string $statement): ?string
    {
        $tokens = $this->tokens($statement);
        if ($tokens === null) {
            return null;
        }
        $root = $tokens[0]['value'];
        if ($root !== 'WITH') {
            return in_array($root, self::OPERATION_WORDS, true) ? $root : null;
        }
        foreach (array_slice($tokens, 1) as $token) {
            if ($token['depth'] === 0 && in_array($token['value'], self::OPERATION_WORDS, true)) {
                return $token['value'];
            }
        }

        return null;
    }

    /** @param list<array{value: string, depth: int}> $tokens */
    public function containsSequence(array $tokens, array $sequence): bool
    {
        $values = array_column($tokens, 'value');
        $length = count($sequence);
        for ($index = 0; $index <= count($values) - $length; $index++) {
            if (array_slice($values, $index, $length) === $sequence) {
                return true;
            }
        }

        return false;
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
}
