<?php

namespace Boy132\UserAttributeMapper\Tests\Unit;

use Boy132\UserAttributeMapper\Attributes\PelicanUserAttributeProvider;
use Boy132\UserAttributeMapper\Events\RegisterUserAttributes;
use Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\AttributeMappingResource;
use Boy132\UserAttributeMapper\Services\UserAttributeRegistry;
use Boy132\UserCreatableServers\Integrations\UserAttributeMapper\UserCreatableServersAttributeProvider;
use Illuminate\Events\Dispatcher;
use LogicException;
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

    public function test_ucs_contributes_four_attributes_through_registration_event(): void
    {
        $registry = new UserAttributeRegistry();
        $events = new Dispatcher();
        $events->listen(RegisterUserAttributes::class, function (RegisterUserAttributes $event): void {
            (new UserCreatableServersAttributeProvider())->register($event->registry);
        });

        (new PelicanUserAttributeProvider())->register($registry);
        $events->dispatch(new RegisterUserAttributes($registry));

        foreach (['cpu', 'memory', 'disk', 'server_limit'] as $attribute) {
            self::assertNotNull($registry->get("user-creatable-servers.$attribute"));
        }
        self::assertCount(11, $registry->all());
        self::assertSame([
            'Pelican' => [
                'pelican.username' => 'Username',
                'pelican.email' => 'Email',
                'pelican.external_id' => 'External ID',
                'pelican.language' => 'Language',
                'pelican.timezone' => 'Timezone',
            ],
            'User Creatable Servers' => [
                'user-creatable-servers.cpu' => 'CPU Limit',
                'user-creatable-servers.memory' => 'Memory Limit',
                'user-creatable-servers.disk' => 'Disk Limit',
                'user-creatable-servers.server_limit' => 'Server Limit',
            ],
        ], AttributeMappingResource::targetAttributeOptions($registry));
    }

    public function test_listener_order_before_core_registration_does_not_change_registry(): void
    {
        $before = $this->buildRegistry(true);
        $after = $this->buildRegistry(false);

        self::assertSame($before->all()->keys()->all(), $after->all()->keys()->all());
        self::assertCount(9, $before->writableFromIdentity());
    }

    private function buildRegistry(bool $extensionRegisteredFirst): UserAttributeRegistry
    {
        $registry = new UserAttributeRegistry();
        $events = new Dispatcher();
        $listen = function () use ($events): void {
            $events->listen(RegisterUserAttributes::class, fn (RegisterUserAttributes $event) => (new UserCreatableServersAttributeProvider())->register($event->registry));
        };
        $scheduleMapper = function () use ($events, $registry): void {
            (new PelicanUserAttributeProvider())->register($registry);
            $events->dispatch(new RegisterUserAttributes($registry));
        };

        // These branches model provider register order. The mapper lifecycle is
        // deliberately run only after both providers have registered.
        if ($extensionRegisteredFirst) {
            $listen();
            $scheduleMapper();
        } else {
            $mapperBootedCallback = $scheduleMapper;
            $listen();
            $mapperBootedCallback();
        }

        return $registry;
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
