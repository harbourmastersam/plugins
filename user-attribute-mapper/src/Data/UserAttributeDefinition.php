<?php

namespace HarbourmasterSam\UserAttributeMapper\Data;

use App\Models\User;
use HarbourmasterSam\UserAttributeMapper\Enums\AttributeType;
use Closure;
use InvalidArgumentException;

final readonly class UserAttributeDefinition
{
    /** @param list<string> $rules */
    public function __construct(
        public string $key,
        public string $owner,
        public string $label,
        public AttributeType $type,
        public Closure $reader,
        public ?Closure $writer = null,
        public ?Closure $clearer = null,
        public string $description = '',
        public ?string $group = null,
        public bool $nullable = false,
        public bool $writableFromIdentity = false,
        public bool $sensitive = false,
        public bool $privileged = false,
        public array $rules = [],
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*\.[a-zA-Z0-9_.-]+$/', $key)) {
            throw new InvalidArgumentException("Attribute key [$key] must be globally namespaced as <owner>.<attribute>.");
        }
        if (!str_starts_with($key, $owner.'.')) {
            throw new InvalidArgumentException("Attribute key [$key] is not in owner namespace [$owner].");
        }
        if ($writableFromIdentity && $writer === null) {
            throw new InvalidArgumentException("Identity-writable attribute [$key] requires a writer.");
        }
    }

    public function read(User $user): mixed
    {
        return ($this->reader)($user);
    }

    public function write(User $user, mixed $value): void
    {
        if (!$this->writableFromIdentity || $this->writer === null) {
            throw new InvalidArgumentException("Attribute [$this->key] is not writable from identity.");
        }
        ($this->writer)($user, $value);
    }

    public function clear(User $user): bool
    {
        if ($this->clearer === null) {
            return false;
        }
        ($this->clearer)($user);
        return true;
    }
}
