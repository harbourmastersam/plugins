<?php

namespace Boy132\UserAttributeMapper\Tests\Unit;

use Boy132\UserAttributeMapper\Attributes\PelicanUserAttributeProvider;
use Boy132\UserAttributeMapper\Enums\AttributeType;
use Boy132\UserAttributeMapper\Services\UserAttributeRegistry;
use PHPUnit\Framework\TestCase;

class PelicanUserAttributeProviderTest extends TestCase
{
    public function test_explicit_safe_pelican_schema_is_registered(): void
    {
        $registry = new UserAttributeRegistry();
        (new PelicanUserAttributeProvider())->register($registry);

        foreach (['username', 'email', 'external_id', 'language', 'timezone', 'is_managed_externally'] as $field) {
            $definition = $registry->get("pelican.$field");
            self::assertNotNull($definition);
            self::assertSame('pelican', $definition->owner);
            self::assertSame('Pelican', $definition->group);
            self::assertTrue($definition->writableFromIdentity);
        }

        $managedExternally = $registry->get('pelican.is_managed_externally');
        self::assertNotNull($managedExternally);
        self::assertSame('pelican', $managedExternally->owner);
        self::assertSame('Pelican', $managedExternally->group);
        self::assertSame(AttributeType::Boolean, $managedExternally->type);
        self::assertTrue($managedExternally->writableFromIdentity);
        self::assertFalse($managedExternally->nullable);
        self::assertNull($managedExternally->clearer);
        self::assertFalse($managedExternally->sensitive);
        self::assertFalse($managedExternally->privileged);

        foreach (['id', 'uuid'] as $field) {
            self::assertFalse($registry->get("pelican.$field")?->writableFromIdentity);
        }
    }

    public function test_sensitive_and_privileged_fields_are_not_registered(): void
    {
        $registry = new UserAttributeRegistry();
        (new PelicanUserAttributeProvider())->register($registry);

        foreach (['password', 'remember_token', 'mfa_app_secret', 'mfa_app_recovery_codes', 'oauth', 'root_admin', 'roles', 'permissions'] as $field) {
            self::assertNull($registry->get("pelican.$field"));
        }
    }
}
