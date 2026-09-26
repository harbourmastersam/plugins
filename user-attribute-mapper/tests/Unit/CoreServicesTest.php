<?php

namespace HarbourmasterSam\UserAttributeMapper\Tests\Unit;

use HarbourmasterSam\UserAttributeMapper\Data\UserAttributeDefinition;
use HarbourmasterSam\UserAttributeMapper\Enums\AttributeType;
use HarbourmasterSam\UserAttributeMapper\Services\AttributeValueConverter;
use HarbourmasterSam\UserAttributeMapper\Services\ClaimPathResolver;
use HarbourmasterSam\UserAttributeMapper\Services\UserAttributeRegistry;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CoreServicesTest extends TestCase
{
    public function test_claim_paths_distinguish_absent_from_falsey_values(): void
    {
        $resolver = new ClaimPathResolver();
        $claims = ['nested' => ['null' => null, 'zero' => 0, 'false' => false, 'empty' => '']];
        foreach (['null', 'zero', 'false', 'empty'] as $key) self::assertTrue($resolver->resolve($claims, "nested.$key")->present);
        self::assertFalse($resolver->resolve($claims, 'nested.missing')->present);
    }

    #[DataProvider('conversions')]
    public function test_strict_conversions(mixed $raw, AttributeType $type, mixed $expected): void
    {
        self::assertSame($expected, (new AttributeValueConverter())->convert($raw, $type, false));
    }

    public static function conversions(): array
    {
        return [['800', AttributeType::Integer, 800], [0, AttributeType::Integer, 0], ['true', AttributeType::Boolean, true], ['false', AttributeType::Boolean, false], [false, AttributeType::Boolean, false], [12, AttributeType::String, '12'], [['a'], AttributeType::Array, ['a']], [['a' => 1], AttributeType::Object, ['a' => 1]]];
    }

    public function test_invalid_integer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AttributeValueConverter())->convert('hello', AttributeType::Integer, false);
    }

    public function test_registry_rejects_duplicate_and_filters_writable_attributes(): void
    {
        $registry = new UserAttributeRegistry();
        $definition = new UserAttributeDefinition('plugin.value', 'plugin', 'Value', AttributeType::String, fn () => null);
        $registry->register($definition);
        self::assertSame($definition, $registry->get('plugin.value'));
        self::assertCount(0, $registry->writableFromIdentity());
        self::assertArrayHasKey('plugin', $registry->grouped()->all());
        $this->expectException(LogicException::class);
        $registry->register($definition);
    }
}
