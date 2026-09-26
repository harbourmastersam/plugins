<?php

namespace Boy132\UserAttributeMapper\Console\Commands;

use Boy132\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use Boy132\UserAttributeMapper\Events\RegisterUserAttributes;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;

final class InspectUserAttributeRegistryCommand extends Command
{
    protected $signature = 'p:user-attribute-mapper:inspect {--require-ucs : Fail unless the UCS bridge registered all User Creatable Servers attributes}';

    protected $description = 'Inspect the runtime user attribute registry and its extension listener.';

    public function handle(UserAttributeRegistryContract $registry, Dispatcher $events): int
    {
        $this->components->info('User Attribute Mapper runtime registry');
        $this->line('Registry object ID: '.spl_object_id($registry));
        $this->line('Registration listeners: '.count($events->getListeners(RegisterUserAttributes::class)));

        $rows = $registry->all()
            ->map(fn ($definition): array => [
                $definition->group ?? $definition->owner,
                $definition->key,
                $definition->type->value,
                $definition->writableFromIdentity ? 'yes' : 'no',
            ])
            ->values()
            ->all();

        $this->table(['Group', 'Key', 'Type', 'Identity writable'], $rows);

        $required = [
            'pelican.username',
            'pelican.email',
            'pelican.external_id',
            'pelican.language',
            'pelican.timezone',
            'pelican.is_managed_externally',
        ];

        if ($this->option('require-ucs')) {
            array_push(
                $required,
                'user-creatable-servers.cpu',
                'user-creatable-servers.memory',
                'user-creatable-servers.disk',
                'user-creatable-servers.server_limit',
            );
        }

        $missing = collect($required)->reject(
            fn (string $key): bool => $registry->get($key)?->writableFromIdentity === true,
        );

        if ($missing->isNotEmpty()) {
            $this->components->error('Missing identity-writable attributes: '.$missing->implode(', '));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Verified %d required attributes (%d identity-writable attributes total).',
            count($required),
            $registry->writableFromIdentity()->count(),
        ));

        return self::SUCCESS;
    }
}
