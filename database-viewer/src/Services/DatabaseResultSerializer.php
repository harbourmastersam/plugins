<?php

namespace GreyHarbour\DatabaseViewer\Services;

use PDO;
use PDOStatement;
use RuntimeException;

final class DatabaseResultSerializer
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public function serialize(PDOStatement $statement, float $durationMs): array
    {
        if (!is_finite($durationMs) || $durationMs < 0) {
            throw new RuntimeException('Invalid query duration.');
        }

        $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rawRows) || !array_is_list($rawRows)) {
            throw new RuntimeException('Invalid database result rows.');
        }

        $rows = [];
        foreach ($rawRows as $rawRow) {
            if (!is_array($rawRow)) {
                throw new RuntimeException('Invalid database result row.');
            }
            $row = [];
            foreach ($rawRow as $name => $value) {
                $columnName = (string) $name;
                $this->assertUtf8($columnName);
                $row[$columnName] = $this->safeValue($value);
            }
            $rows[] = $row;
        }

        $fallbackNames = $rawRows === [] ? [] : array_map('strval', array_keys($rawRows[0]));
        $headers = [];
        $columnCount = $statement->columnCount();
        for ($index = 0; $index < $columnCount; $index++) {
            $metadata = $statement->getColumnMeta($index);
            $name = is_array($metadata) && isset($metadata['name']) && is_string($metadata['name'])
                ? $metadata['name']
                : ($fallbackNames[$index] ?? null);
            if ($name === null) {
                throw new RuntimeException('Database column metadata is unavailable.');
            }
            $this->assertUtf8($name);
            $nativeType = is_array($metadata) && isset($metadata['native_type']) && is_string($metadata['native_type'])
                ? $metadata['native_type']
                : null;
            if ($nativeType !== null) {
                $this->assertUtf8($nativeType);
            }
            $headers[] = [
                'name' => $name,
                'displayName' => $name,
                'originalType' => $nativeType,
                'type' => $this->renderHint($nativeType),
            ];
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'stat' => [
                'rowsAffected' => 0,
                'rowsRead' => count($rows),
                'rowsWritten' => null,
                'queryDurationMs' => $durationMs,
            ],
        ];
    }

    private function safeValue(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $this->assertUtf8($value);

            return $value;
        }
        if (is_int($value)) {
            return abs($value) > self::MAX_SAFE_INTEGER ? (string) $value : $value;
        }
        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        throw new RuntimeException('Database result contains an unsupported value.');
    }

    private function assertUtf8(string $value): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new RuntimeException('Database result contains invalid UTF-8.');
        }
    }

    private function renderHint(?string $nativeType): int
    {
        return match (strtoupper($nativeType ?? '')) {
            'TINY', 'SHORT', 'LONG', 'LONGLONG', 'INT24', 'YEAR' => 2,
            'FLOAT', 'DOUBLE', 'DECIMAL', 'NEWDECIMAL' => 3,
            'BLOB', 'TINY_BLOB', 'MEDIUM_BLOB', 'LONG_BLOB', 'BINARY', 'VARBINARY', 'BIT' => 4,
            default => 1,
        };
    }
}
