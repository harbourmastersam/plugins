<?php

namespace Boy132\UserAttributeMapper\Tests\Unit;

use Boy132\UserAttributeMapper\Events\RegisterUserAttributes;
use Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\AttributeMappingResource;
use Boy132\UserAttributeMapper\Services\UserAttributeRegistry;
use Boy132\UserCreatableServers\Integrations\UserAttributeMapper\UserCreatableServersAttributeProvider;
use Illuminate\Events\Dispatcher;
use LogicException;
use PHPUnit\Framework\TestCase;

class ExtensionRegistrationTest extends TestCase
{
    public function test_mapper_operates_with_no_attribute_providers(): void
    {
        $registry = new UserAttributeRegistry();

        (new Dispatcher())->dispatch(new RegisterUserAttributes($registry));

        self::assertCount(0, $registry->all());
        self::assertSame([], AttributeMappingResource::targetAttributeOptions($registry));
    }

    public function test_ucs_contributes_four_attributes_through_registration_event(): void
    {
        $registry = new UserAttributeRegistry();
        $events = new Dispatcher();
        $events->listen(RegisterUserAttributes::class, function (RegisterUserAttributes $event): void {
            (new UserCreatableServersAttributeProvider())->register($event->registry);
        });

        $events->dispatch(new RegisterUserAttributes($registry));

        foreach (['cpu', 'memory', 'disk', 'server_limit'] as $attribute) {
            self::assertNotNull($registry->get("user-creatable-servers.$attribute"));
        }
        self::assertCount(4, $registry->all());
        self::assertSame([
            'User Creatable Servers' => [
                'user-creatable-servers.cpu' => 'CPU Limit',
                'user-creatable-servers.memory' => 'Memory Limit',
                'user-creatable-servers.disk' => 'Disk Limit',
                'user-creatable-servers.server_limit' => 'Server Limit',
            ],
        ], AttributeMappingResource::targetAttributeOptions($registry));
    }

    public function test_duplicate_registration_is_still_rejected(): void
    {
        $registry = new UserAttributeRegistry();
        $provider = new UserCreatableServersAttributeProvider();
        $provider->register($registry);

        $this->expectException(LogicException::class);
        $provider->register($registry);
    }
}
