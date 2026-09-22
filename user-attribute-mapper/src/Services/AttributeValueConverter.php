<?php

namespace Boy132\UserAttributeMapper\Services;

use Boy132\UserAttributeMapper\Enums\AttributeType;
use InvalidArgumentException;
use stdClass;

class AttributeValueConverter
{
    public function convert(mixed $value, AttributeType $type, bool $nullable): mixed
    {
        if ($value === null) {
            if ($nullable) return null;
            throw new InvalidArgumentException('Null is not allowed.');
        }
        return match ($type) {
            AttributeType::String => $this->string($value),
            AttributeType::Integer => $this->integer($value),
            AttributeType::Float => $this->float($value),
            AttributeType::Boolean => $this->boolean($value),
            AttributeType::Array => is_array($value) ? $value : throw new InvalidArgumentException('Expected an array.'),
            AttributeType::Object => $this->object($value),
        };
    }

    private function string(mixed $value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) throw new InvalidArgumentException('Expected a scalar string value.');
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) return $value;
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value)) throw new InvalidArgumentException('Expected an integer.');
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : throw new InvalidArgumentException('Integer is out of range.');
    }

    private function float(mixed $value): float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) throw new InvalidArgumentException('Expected a finite number.');
        $converted = (float) $value;
        if (!is_finite($converted)) throw new InvalidArgumentException('Expected a finite number.');
        return $converted;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if ($value === 1 || $value === '1' || $value === 'true') return true;
        if ($value === 0 || $value === '0' || $value === 'false') return false;
        throw new InvalidArgumentException('Expected true, false, 1, or 0.');
    }

    private function object(mixed $value): array
    {
        if ($value instanceof stdClass) return get_object_vars($value);
        if (is_array($value) && !array_is_list($value)) return $value;
        throw new InvalidArgumentException('Expected an object.');
    }
}
