<?php

namespace Boy132\UserAttributeMapper\Tests\Unit;

use Boy132\UserAttributeMapper\Attributes\PelicanUserAttributeProvider;
use Boy132\UserAttributeMapper\Events\RegisterUserAttributes;
use Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\AttributeMappingResource;
use Boy132\UserAttributeMapper\Services\UserAttributeRegistry;
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
        ], $registry->writableFromIdentity()->keys()->all());
        self::assertArrayHasKey('Pelican', AttributeMappingResource::targetAttributeOptions($registry));
    }
}
