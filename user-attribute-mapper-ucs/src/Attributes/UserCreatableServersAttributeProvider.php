<?php

namespace HarbourmasterSam\UserAttributeMapperUcs\Attributes;

use Boy132\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use Boy132\UserAttributeMapper\Data\UserAttributeDefinition;
use Boy132\UserAttributeMapper\Enums\AttributeType;
use Boy132\UserCreatableServers\Models\UserResourceLimits;

final class UserCreatableServersAttributeProvider
{
    /** @var array<string, array{label: string, nullable: bool, description: string}> */
    private const SUPPORTED_ATTRIBUTES = [
        'cpu' => [
            'label' => 'CPU Limit',
            'nullable' => false,
            'description' => 'Maximum CPU allocation available to servers created by this user. Zero means no aggregate CPU limit.',
        ],
        'memory' => [
            'label' => 'Memory Limit',
            'nullable' => false,
            'description' => 'Maximum memory allocation available to servers created by this user. Zero means no aggregate memory limit.',
        ],
        'disk' => [
            'label' => 'Disk Limit',
            'nullable' => false,
            'description' => 'Maximum disk allocation available to servers created by this user. Zero means no aggregate disk limit.',
        ],
        'server_limit' => [
            'label' => 'Server Limit',
            'nullable' => true,
            'description' => 'Maximum number of servers this user may create. Zero or null means no server-count limit.',
        ],
    ];

    public function register(UserAttributeRegistryContract $registry): void
    {
        $available = (new UserResourceLimits())->getFillable();

        foreach (self::SUPPORTED_ATTRIBUTES as $field => $metadata) {
            if (!in_array($field, $available, true)) {
                continue;
            }

            $nullable = $metadata['nullable'];
            $registry->register(new UserAttributeDefinition(
                key: "user-creatable-servers.$field",
                owner: 'user-creatable-servers',
                label: $metadata['label'],
                type: AttributeType::Integer,
                reader: fn ($user) => UserResourceLimits::where('user_id', $user->id)->value($field),
                writer: function ($user, ?int $value) use ($field): void {
                    $limits = UserResourceLimits::firstOrNew(['user_id' => $user->id]);
                    if (!$limits->exists) {
                        $limits->fill(['cpu' => 0, 'memory' => 0, 'disk' => 0, 'server_limit' => null]);
                    }
                    $limits->{$field} = $value;
                    $limits->save();
                },
                clearer: $nullable ? function ($user) use ($field): void {
                    $limits = UserResourceLimits::where('user_id', $user->id)->first();
                    if ($limits !== null) {
                        $limits->{$field} = null;
                        $limits->save();
                    }
                } : null,
                description: $metadata['description'],
                group: 'User Creatable Servers',
                nullable: $nullable,
                writableFromIdentity: true,
                rules: $nullable
                    ? ['nullable', 'integer', 'min:0']
                    : ['required', 'integer', 'min:0'],
            ));
        }
    }
}
