<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use App\Models\User;
use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class UserAttributeService
{
    public function __construct(private readonly UserAttributeRegistryContract $registry, private readonly AttributeValueConverter $converter) {}

    public function get(User $user, string $key): mixed
    {
        return $this->registry->get($key)?->read($user);
    }

    public function setFromIdentity(User $user, string $key, mixed $raw): void
    {
        $definition = $this->registry->get($key) ?? throw new InvalidArgumentException("Attribute [$key] is unavailable.");
        if (!$definition->writableFromIdentity) throw new InvalidArgumentException("Attribute [$key] is not identity-writable.");
        $value = $this->converter->convert($raw, $definition->type, $definition->nullable);
        if ($definition->rules !== []) {
            $validator = Validator::make(['value' => $value], ['value' => $definition->rules]);
            if ($validator->fails()) throw new InvalidArgumentException($validator->errors()->first('value'));
        }
        $definition->write($user, $value);
    }

    public function clearFromIdentity(User $user, string $key): bool
    {
        $definition = $this->registry->get($key);
        return $definition !== null && $definition->writableFromIdentity && $definition->clear($user);
    }
}
