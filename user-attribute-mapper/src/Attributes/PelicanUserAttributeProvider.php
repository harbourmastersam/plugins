<?php

namespace Boy132\UserAttributeMapper\Attributes;

use App\Models\User;
use Boy132\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use Boy132\UserAttributeMapper\Data\UserAttributeDefinition;
use Boy132\UserAttributeMapper\Enums\AttributeType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class PelicanUserAttributeProvider
{
    public function register(UserAttributeRegistryContract $registry): void
    {
        foreach ([
            'username' => ['Username', false, ['required', 'string', 'between:1,191']],
            'email' => ['Email', false, ['required', 'string', 'email', 'max:191']],
            'external_id' => ['External ID', true, ['nullable', 'string', 'max:191']],
            'language' => ['Language', false, ['required', 'string', 'min:2', 'max:5']],
            'timezone' => ['Timezone', false, ['required', 'timezone']],
        ] as $field => [$label, $nullable, $fallbackRules]) {
            $registry->register(new UserAttributeDefinition(
                key: "pelican.$field",
                owner: 'pelican',
                label: $label,
                type: AttributeType::String,
                reader: fn (User $user) => $user->{$field},
                writer: fn (User $user, ?string $value) => $this->write($user, $field, $value, $fallbackRules),
                clearer: $nullable ? fn (User $user) => $this->write($user, $field, null, $fallbackRules) : null,
                description: "Pelican user $label.",
                group: 'Pelican',
                nullable: $nullable,
                writableFromIdentity: true,
            ));
        }

        $registry->register(new UserAttributeDefinition(
            key: 'pelican.is_managed_externally',
            owner: 'pelican',
            label: 'Is Managed Externally',
            type: AttributeType::Boolean,
            reader: fn (User $user): bool => (bool) $user->is_managed_externally,
            writer: fn (User $user, bool $value) => $this->write(
                $user,
                'is_managed_externally',
                $value,
                ['required', 'boolean'],
            ),
            description: "Whether Pelican treats this user's identity as externally managed. When enabled, Pelican prevents the user from changing externally managed identity fields such as username, email, and password.",
            group: 'Pelican',
            nullable: false,
            writableFromIdentity: true,
            sensitive: false,
            privileged: false,
        ));

        foreach (['id' => 'ID', 'uuid' => 'UUID'] as $field => $label) {
            $registry->register(new UserAttributeDefinition(
                key: "pelican.$field",
                owner: 'pelican',
                label: $label,
                type: $field === 'id' ? AttributeType::Integer : AttributeType::String,
                reader: fn (User $user) => $user->{$field},
                description: "Read-only Pelican user $label.",
                group: 'Pelican',
            ));
        }
    }

    /** @param array<int, mixed> $fallbackRules */
    private function write(User $user, string $field, mixed $value, array $fallbackRules): void
    {
        $rules = $fallbackRules;

        // Pelican models expose their canonical update rules through this helper.
        // Retain the explicit allowlisted fallback for compatible releases where
        // the helper is unavailable, and always scope unique rules to this user.
        if (method_exists(User::class, 'getRulesForUpdate')) {
            $modelRules = User::getRulesForUpdate($user);
            if (isset($modelRules[$field])) {
                $rules = $modelRules[$field];
            }
        } else {
            if (in_array($field, ['username', 'email', 'external_id'], true)) {
                $rules[] = Rule::unique('users', $field)->ignore($user->getKey());
            }
        }

        Validator::make([$field => $value], [$field => $rules])->validate();

        $user->{$field} = $value;
        $user->save();
    }
}
