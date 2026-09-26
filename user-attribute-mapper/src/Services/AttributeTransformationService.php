<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use HarbourmasterSam\UserAttributeMapper\Enums\AttributeTransform;
use InvalidArgumentException;

/** A deliberately non-extensible, literal-only string transformation pipeline. */
class AttributeTransformationService
{
    /** @param array<int, array<string, mixed>> $transforms */
    public function transform(mixed $value, array $transforms): mixed
    {
        if ($transforms === []) return $value;
        if (!is_string($value)) throw new InvalidArgumentException('Transformations require a string value.');

        foreach ($transforms as $configuration) {
            if (!is_array($configuration)) throw new InvalidArgumentException('Invalid transformation configuration.');
            $transform = AttributeTransform::tryFrom((string) ($configuration['type'] ?? ''))
                ?? throw new InvalidArgumentException('Unsupported transformation.');
            $value = match ($transform) {
                AttributeTransform::Trim => trim($value),
                AttributeTransform::Lowercase => mb_strtolower($value),
                AttributeTransform::Uppercase => mb_strtoupper($value),
                AttributeTransform::Prefix => $this->argument($configuration, 'value').$value,
                AttributeTransform::Suffix => $value.$this->argument($configuration, 'value'),
                AttributeTransform::Replace => str_replace(
                    $this->argument($configuration, 'from'),
                    $this->argument($configuration, 'to'),
                    $value,
                ),
            };
        }

        return $value;
    }

    /** @param array<int, array<string, mixed>> $transforms */
    public function validate(array $transforms): void
    {
        $this->transform('', $transforms);
    }

    /** @param array<string, mixed> $configuration */
    private function argument(array $configuration, string $key): string
    {
        if (!array_key_exists($key, $configuration) || !is_string($configuration[$key])) {
            throw new InvalidArgumentException("Transformation [$key] must be text.");
        }
        return $configuration[$key];
    }
}
