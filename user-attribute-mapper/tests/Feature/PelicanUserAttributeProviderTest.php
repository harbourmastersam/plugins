<?php

use App\Models\User;
use Boy132\UserAttributeMapper\Attributes\PelicanUserAttributeProvider;
use Boy132\UserAttributeMapper\Services\AttributeValueConverter;
use Boy132\UserAttributeMapper\Services\UserAttributeRegistry;
use Boy132\UserAttributeMapper\Services\UserAttributeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes true and false externally managed values through the conversion pipeline', function (): void {
    $user = User::factory()->create(['is_managed_externally' => false]);
    $registry = new UserAttributeRegistry();
    (new PelicanUserAttributeProvider())->register($registry);
    $attributes = new UserAttributeService($registry, new AttributeValueConverter());

    $attributes->setFromIdentity($user, 'pelican.is_managed_externally', true);

    expect($user->fresh()->is_managed_externally)->toBeTrue();

    $attributes->setFromIdentity($user, 'pelican.is_managed_externally', false);

    expect($user->fresh()->is_managed_externally)->toBeFalse();
});
