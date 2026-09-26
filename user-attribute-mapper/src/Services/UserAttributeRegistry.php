<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use HarbourmasterSam\UserAttributeMapper\Data\UserAttributeDefinition;
use Illuminate\Support\Collection;
use LogicException;

class UserAttributeRegistry implements UserAttributeRegistryContract
{
    /** @var array<string, UserAttributeDefinition> */
    private array $definitions = [];

    public function register(UserAttributeDefinition $definition): void
    {
        if (isset($this->definitions[$definition->key])) {
            throw new LogicException("User attribute [$definition->key] is already registered by [{$this->definitions[$definition->key]->owner}].");
        }
        $this->definitions[$definition->key] = $definition;
    }

    public function get(string $key): ?UserAttributeDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function all(): Collection
    {
        return collect($this->definitions);
    }

    public function writableFromIdentity(): Collection
    {
        return $this->all()->filter(fn (UserAttributeDefinition $definition) => $definition->writableFromIdentity);
    }

    public function grouped(): Collection
    {
        return $this->all()->groupBy(fn (UserAttributeDefinition $definition) => $definition->group ?? $definition->owner);
    }
}
