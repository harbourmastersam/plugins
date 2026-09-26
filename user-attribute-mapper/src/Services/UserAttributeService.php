<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use App\Models\User;
use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use HarbourmasterSam\UserAttributeMapper\Enums\AttributeMutationResult;

class UserAttributeService
{
    public function __construct(private readonly UserAttributeRegistryContract $registry, private readonly AttributeValueConverter $converter) {}

    public function get(User $user, string $key): mixed
    {
        return $this->registry->get($key)?->read($user);
    }

    public function convertAndValidate(string $key, mixed $raw): mixed
    {
        $definition = $this->registry->get($key) ?? throw new InvalidArgumentException("Attribute [$key] is unavailable.");
        if (!$definition->writableFromIdentity) throw new InvalidArgumentException("Attribute [$key] is not identity-writable.");
        $value = $this->converter->convert($raw, $definition->type, $definition->nullable);
        if ($definition->rules !== []) {
            $validator = Validator::make(['value' => $value], ['value' => $definition->rules]);
            if ($validator->fails()) throw new InvalidArgumentException($validator->errors()->first('value'));
        }
        return $value;
    }

    public function setFromIdentity(User $user, string $key, mixed $raw): AttributeMutationResult
    {
        $definition = $this->registry->get($key) ?? throw new InvalidArgumentException("Attribute [$key] is unavailable.");
        $value = $this->convertAndValidate($key, $raw);
        if ($definition->compareBeforeWrite) {
            try {
                $current = $this->converter->convert($definition->read($user), $definition->type, $definition->nullable);
                if ($current === $value) return AttributeMutationResult::Unchanged;
            } catch (\Throwable) {
                // An unreadable legacy value must not prevent the authoritative write.
            }
        }
        $definition->write($user, $value);
        return AttributeMutationResult::Updated;
    }

    public function clearFromIdentity(User $user, string $key): AttributeMutationResult
    {
        $definition = $this->registry->get($key);
        if ($definition === null || !$definition->writableFromIdentity || $definition->clearer === null) {
            return AttributeMutationResult::Unchanged;
        }
        if ($definition->compareBeforeWrite) {
            try {
                if ($definition->read($user) === null) return AttributeMutationResult::Unchanged;
            } catch (\Throwable) {
                // A failed comparison should not prevent the requested clear.
            }
        }
        $definition->clear($user);
        return AttributeMutationResult::Cleared;
    }
}
