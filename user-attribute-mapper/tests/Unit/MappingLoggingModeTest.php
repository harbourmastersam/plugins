<?php

namespace HarbourmasterSam\UserAttributeMapper\Tests\Unit;

use HarbourmasterSam\UserAttributeMapper\Enums\MappingLoggingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MappingLoggingModeTest extends TestCase
{
    #[DataProvider('modes')]
    public function test_mode_semantics(string $value, bool $summary, bool $individual): void
    {
        $mode = MappingLoggingMode::resolve($value);

        self::assertSame($value, $mode->value);
        self::assertSame($summary, $mode->logsSummary());
        self::assertSame($individual, $mode->logsIndividualResults());
    }

    public static function modes(): array
    {
        return [
            'errors' => ['errors', false, false],
            'normal' => ['normal', true, false],
            'verbose' => ['verbose', true, true],
        ];
    }

    public function test_invalid_config_falls_back_to_normal(): void
    {
        self::assertSame(MappingLoggingMode::Normal, MappingLoggingMode::resolve('not-a-mode'));
        self::assertSame(MappingLoggingMode::Normal, MappingLoggingMode::resolve(null));
    }
}
