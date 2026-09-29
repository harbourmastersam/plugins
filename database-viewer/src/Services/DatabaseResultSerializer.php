<?php

namespace GreyHarbour\DatabaseViewer\Services;

use PDO;
use PDOStatement;
use RuntimeException;

final class DatabaseResultSerializer
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public function serialize(PDOStatement $statement, float $durationMs, string|false $lastInsertId = false): array
    {
        if (!is_finite($durationMs) || $durationMs < 0) {
            throw new RuntimeException('Invalid query duration.');
        }

        $columnCount = $statement->columnCount();
        if ($columnCount === 0) {
            $rowsAffected = $statement->rowCount();
            $result = [
                'headers' => [],
                'rows' => [],
                'stat' => [
                    'rowsAffected' => $rowsAffected,
                    'rowsRead' => 0,
                    'rowsWritten' => $rowsAffected,
                    'queryDurationMs' => $durationMs,
                ],
            ];
            $safeInsertId = $this->safeInsertId($lastInsertId);
            if ($safeInsertId !== null) {
                $result['lastInsertRowid'] = $safeInsertId;
            }
            $this->assertResponseFits($result);

            return $result;
        }

        $firstRawRow = $statement->fetch(PDO::FETCH_ASSOC);
        if ($firstRawRow !== false && !is_array($firstRawRow)) {
            throw new RuntimeException('Invalid database result row.');
        }
        $fallbackNames = is_array($firstRawRow) ? array_map('strval', array_keys($firstRawRow)) : [];
        $headers = $this->headers($statement, $columnCount, $fallbackNames);
        $rows = [];
        $encodedRowBytes = 0;
        $rawRow = $firstRawRow;
        while ($rawRow !== false) {
            $row = [];
            foreach ($rawRow as $name => $value) {
                $columnName = (string) $name;
                $this->assertUtf8($columnName);
                $row[$columnName] = $this->safeValue($value);
            }
            $encodedRowBytes += strlen(json_encode($row, JSON_THROW_ON_ERROR)) + 1;
            if ($encodedRowBytes > BrokerLimits::MAX_RESPONSE_BYTES) {
                throw new RuntimeException('Database result exceeds the response limit.');
            }
            $rows[] = $row;
            $rawRow = $statement->fetch(PDO::FETCH_ASSOC);
            if ($rawRow !== false && !is_array($rawRow)) {
                throw new RuntimeException('Invalid database result row.');
            }
        }

        $result = [
            'headers' => $headers,
            'rows' => $rows,
            'stat' => [
                'rowsAffected' => 0,
                'rowsRead' => count($rows),
                'rowsWritten' => null,
                'queryDurationMs' => $durationMs,
            ],
        ];
        $this->assertResponseFits($result);

        return $result;
    }

    /** @param list<string> $fallbackNames */
    private function headers(PDOStatement $statement, int $columnCount, array $fallbackNames): array
    {
        $headers = [];
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

        return $headers;
    }

    private function safeInsertId(string|false $lastInsertId): ?int
    {
        if (!is_string($lastInsertId)
            || preg_match('/\A[1-9][0-9]*\z/D', $lastInsertId) !== 1
            || strlen($lastInsertId) > 16
            || (strlen($lastInsertId) === 16 && strcmp($lastInsertId, (string) self::MAX_SAFE_INTEGER) > 0)) {
            return null;
        }

        return (int) $lastInsertId;
    }

    private function assertResponseFits(array $result): void
    {
        $json = json_encode(['data' => $result], JSON_THROW_ON_ERROR);
        if (strlen($json) > BrokerLimits::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Database result exceeds the response limit.');
        }
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
