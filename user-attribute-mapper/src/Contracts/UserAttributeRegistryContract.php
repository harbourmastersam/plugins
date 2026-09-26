<?php

namespace HarbourmasterSam\UserAttributeMapper\Contracts;

use HarbourmasterSam\UserAttributeMapper\Data\UserAttributeDefinition;
use Illuminate\Support\Collection;

interface UserAttributeRegistryContract
{
    public function register(UserAttributeDefinition $definition): void;

    public function get(string $key): ?UserAttributeDefinition;

    /** @return Collection<string, UserAttributeDefinition> */
    public function all(): Collection;

    /** @return Collection<string, UserAttributeDefinition> */
    public function writableFromIdentity(): Collection;

    /** @return Collection<string, Collection<string, UserAttributeDefinition>> */
    public function grouped(): Collection;
}
