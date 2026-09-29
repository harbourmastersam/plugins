<?php

namespace GreyHarbour\DatabaseViewer\Tests;

use GreyHarbour\DatabaseViewer\Services\DatabaseResultSerializer;
use GreyHarbour\DatabaseViewer\Services\BrokerLimits;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseResultSerializerTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private function statement(array $rows, array $metadata): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->atLeastOnce())
            ->method('fetch')
            ->with(PDO::FETCH_ASSOC)
            ->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
        $statement->expects($this->never())->method('fetchAll');
        $statement->method('columnCount')->willReturn(count($metadata));
        $statement->method('getColumnMeta')->willReturnCallback(fn (int $index) => $metadata[$index]);

        return $statement;
    }

    private function command(int $rowCount): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('columnCount')->willReturn(0);
        $statement->expects($this->once())->method('rowCount')->willReturn($rowCount);
        $statement->expects($this->never())->method('fetch');

        return $statement;
    }

    public function test_empty_result_uses_pdo_metadata_and_exact_read_stats(): void
    {
        $result = (new DatabaseResultSerializer())->serialize($this->statement([], [
            ['name' => 'id', 'native_type' => 'LONGLONG'],
        ]), 1.25);

        $this->assertSame([['name' => 'id', 'displayName' => 'id', 'originalType' => 'LONGLONG', 'type' => 2]], $result['headers']);
        $this->assertSame([], $result['rows']);
        $this->assertSame(['rowsAffected' => 0, 'rowsRead' => 0, 'rowsWritten' => null, 'queryDurationMs' => 1.25], $result['stat']);
        $this->assertArrayNotHasKey('lastInsertRowid', $result);
    }

    public function test_false_column_metadata_falls_back_to_row_keys(): void
    {
        $result = (new DatabaseResultSerializer())->serialize($this->statement([
            ['alpha' => 'one', 'beta' => 2],
        ], [false, false]), 0);

        $this->assertSame([
            ['name' => 'alpha', 'displayName' => 'alpha', 'originalType' => null, 'type' => 1],
            ['name' => 'beta', 'displayName' => 'beta', 'originalType' => null, 'type' => 1],
        ], $result['headers']);
        $this->assertSame([['alpha' => 'one', 'beta' => 2]], $result['rows']);
        $this->assertSame(1, $result['stat']['rowsRead']);
    }

    public function test_render_hints_and_json_safe_scalars_are_preserved(): void
    {
        $rows = [['text' => 'ok', 'integer' => 42, 'real' => 1.5, 'blob' => "a\u{0001}b", 'nullable' => null, 'flag' => true]];
        $metadata = [
            ['name' => 'text', 'native_type' => 'VAR_STRING'],
            ['name' => 'integer', 'native_type' => 'LONG'],
            ['name' => 'real', 'native_type' => 'NEWDECIMAL'],
            ['name' => 'blob', 'native_type' => 'BLOB'],
            ['name' => 'nullable', 'native_type' => 'NULL'],
            ['name' => 'flag', 'native_type' => 'TINY'],
        ];

        $result = (new DatabaseResultSerializer())->serialize($this->statement($rows, $metadata), 2);

        $this->assertSame([1, 2, 3, 4, 1, 2], array_column($result['headers'], 'type'));
        $this->assertSame($rows, $result['rows']);
    }

    public function test_integer_outside_javascript_safe_range_becomes_decimal_string(): void
    {
        $result = (new DatabaseResultSerializer())->serialize($this->statement([
            ['positive' => 9007199254740992, 'negative' => -9007199254740992],
        ], [
            ['name' => 'positive', 'native_type' => 'LONGLONG'],
            ['name' => 'negative', 'native_type' => 'LONGLONG'],
        ]), 0);

        $this->assertSame([['positive' => '9007199254740992', 'negative' => '-9007199254740992']], $result['rows']);
    }

    public function test_commands_report_write_statistics_and_safe_insert_id(): void
    {
        $result = (new DatabaseResultSerializer())->serialize($this->command(3), 4.5, '9007199254740991');

        $this->assertSame([], $result['headers']);
        $this->assertSame([], $result['rows']);
        $this->assertSame([
            'rowsAffected' => 3,
            'rowsRead' => 0,
            'rowsWritten' => 3,
            'queryDurationMs' => 4.5,
        ], $result['stat']);
        $this->assertSame(9007199254740991, $result['lastInsertRowid']);
    }

    #[DataProvider('omittedInsertIds')]
    public function test_unsafe_or_non_decimal_insert_ids_are_omitted(string|false $insertId): void
    {
        $result = (new DatabaseResultSerializer())->serialize($this->command(0), 0, $insertId);

        $this->assertArrayNotHasKey('lastInsertRowid', $result);
    }

    public static function omittedInsertIds(): array
    {
        return [
            'not requested' => [false],
            'oversized' => ['9007199254740992'],
            'negative' => ['-1'],
            'non decimal' => ['1e3'],
        ];
    }

    public function test_encoded_size_overflow_aborts_without_returning_partial_rows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('response limit');

        (new DatabaseResultSerializer())->serialize($this->statement([
            ['value' => str_repeat('x', BrokerLimits::MAX_RESPONSE_BYTES)],
        ], [
            ['name' => 'value', 'native_type' => 'VAR_STRING'],
        ]), 0);
    }

    public static function unsafeValues(): array
    {
        return [
            'positive infinity' => [INF],
            'negative infinity' => [-INF],
            'not a number' => [NAN],
            'array' => [[1]],
            'object' => [(object) ['value' => 1]],
            'invalid UTF-8' => ["\xB1\x31"],
        ];
    }

    #[DataProvider('unsafeValues')]
    public function test_unsupported_row_values_fail_the_entire_result(mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        (new DatabaseResultSerializer())->serialize($this->statement([['value' => $value]], [false]), 0);
    }

    public function test_resources_fail_the_entire_result(): void
    {
        $resource = fopen('php://memory', 'rb');
        try {
            $this->expectException(RuntimeException::class);
            (new DatabaseResultSerializer())->serialize($this->statement([['value' => $resource]], [false]), 0);
        } finally {
            fclose($resource);
        }
    }

    public function test_invalid_duration_fails(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $this->expectException(RuntimeException::class);
        (new DatabaseResultSerializer())->serialize($statement, INF);
    }

    public function test_column_metadata_failure_aborts_serialization(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetch')->willReturn(false);
        $statement->method('columnCount')->willReturn(1);
        $statement->method('getColumnMeta')->willThrowException(new RuntimeException('metadata failed'));

        $this->expectException(RuntimeException::class);
        (new DatabaseResultSerializer())->serialize($statement, 0);
    }
}
