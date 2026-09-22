<?php

namespace Boy132\UserCreatableServers\Integrations\UserAttributeMapper;

use Boy132\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use Boy132\UserAttributeMapper\Data\UserAttributeDefinition;
use Boy132\UserAttributeMapper\Enums\AttributeType;
use Boy132\UserCreatableServers\Models\UserResourceLimits;

final class UserCreatableServersAttributeProvider
{
    public function register(UserAttributeRegistryContract $registry): void
    {
        foreach ([
            'cpu' => ['CPU Limit', false],
            'memory' => ['Memory Limit', false],
            'disk' => ['Disk Limit', false],
            'server_limit' => ['Server Limit', true],
        ] as $field => [$label, $nullable]) {
            $registry->register(new UserAttributeDefinition(
                key: "user-creatable-servers.$field",
                owner: 'user-creatable-servers',
                label: $label,
                type: AttributeType::Integer,
                reader: fn ($user) => UserResourceLimits::where('user_id', $user->id)->value($field),
                writer: function ($user, ?int $value) use ($field): void {
                    $limits = UserResourceLimits::firstOrNew(['user_id' => $user->id]);
                    if (!$limits->exists) $limits->fill(['cpu' => 0, 'memory' => 0, 'disk' => 0, 'server_limit' => null]);
                    $limits->{$field} = $value;
                    $limits->save();
                },
                clearer: $nullable ? function ($user) use ($field): void {
                    $limits = UserResourceLimits::where('user_id', $user->id)->first();
                    if ($limits) { $limits->{$field} = null; $limits->save(); }
                } : null,
                description: 'Existing user-level server resource limit owned by User Creatable Servers.',
                group: 'User Creatable Servers',
                nullable: $nullable,
                writableFromIdentity: true,
                rules: $nullable ? ['nullable', 'integer', 'min:0'] : ['required', 'integer', 'min:0'],
            ));
        }
    }
}
