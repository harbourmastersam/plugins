<?php

use App\Models\User;
use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use HarbourmasterSam\UserAttributeMapper\Data\UserAttributeDefinition;
use HarbourmasterSam\UserAttributeMapper\Enums\AttributeMutationResult;
use HarbourmasterSam\UserAttributeMapper\Enums\AttributeType;
use HarbourmasterSam\UserAttributeMapper\Services\AttributeValueConverter;
use HarbourmasterSam\UserAttributeMapper\Services\UserAttributeService;

it('normalises values and skips unchanged identity writes', function (mixed $current, mixed $incoming, AttributeType $type): void {
    $writes = 0;
    $definition = new UserAttributeDefinition(key: 'test.value', owner: 'test', label: 'Value', type: $type,
        reader: fn () => $current, writer: function () use (&$writes): void { $writes++; }, writableFromIdentity: true);
    $registry = Mockery::mock(UserAttributeRegistryContract::class);
    $registry->shouldReceive('get')->andReturn($definition);
    $result = (new UserAttributeService($registry, new AttributeValueConverter()))->setFromIdentity(new User(), 'test.value', $incoming);
    expect($result)->toBe(AttributeMutationResult::Unchanged)->and($writes)->toBe(0);
})->with([['sam', 'sam', AttributeType::String], [false, 'false', AttributeType::Boolean], [0, '0', AttributeType::Integer]]);

it('supports side-effect writers opting out of comparison', function (): void {
    $writes = 0;
    $definition = new UserAttributeDefinition(key: 'test.value', owner: 'test', label: 'Value', type: AttributeType::String,
        reader: fn () => 'same', writer: function () use (&$writes): void { $writes++; }, writableFromIdentity: true, compareBeforeWrite: false);
    $registry = Mockery::mock(UserAttributeRegistryContract::class);
    $registry->shouldReceive('get')->andReturn($definition);
    $result = (new UserAttributeService($registry, new AttributeValueConverter()))->setFromIdentity(new User(), 'test.value', 'same');
    expect($result)->toBe(AttributeMutationResult::Updated)->and($writes)->toBe(1);
});
