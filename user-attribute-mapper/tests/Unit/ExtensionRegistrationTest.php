<?php

namespace HarbourmasterSam\UserAttributeMapper\Tests\Unit;

use HarbourmasterSam\UserAttributeMapper\Attributes\PelicanUserAttributeProvider;
use HarbourmasterSam\UserAttributeMapper\Events\RegisterUserAttributes;
use HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\AttributeMappingResource;
use HarbourmasterSam\UserAttributeMapper\Services\UserAttributeRegistry;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

class ExtensionRegistrationTest extends TestCase
{
    public function test_mapper_registers_core_attributes_without_extensions(): void
    {
        $registry = new UserAttributeRegistry();

        (new PelicanUserAttributeProvider())->register($registry);
        (new Dispatcher())->dispatch(new RegisterUserAttributes($registry));

        self::assertSame([
            'pelican.username',
            'pelican.email',
            'pelican.external_id',
            'pelican.language',
            'pelican.timezone',
            'pelican.is_managed_externally',
        ], $registry->writableFromIdentity()->keys()->all());
        self::assertArrayHasKey('Pelican', AttributeMappingResource::targetAttributeOptions($registry));
    }
}
