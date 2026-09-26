<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use HarbourmasterSam\UserAttributeMapper\Enums\MappingSourceType;

/** Pure source resolution shared by login mutation and administrator preview. */
class MappingCandidateResolver
{
    public function __construct(private readonly ClaimPathResolver $claims, private readonly AttributeTransformationService $transformations) {}

    /** @param array<string, mixed> $claims @return array{present: bool, value: mixed} */
    public function resolve(MappingSourceType $sourceType, string $sourceValue, array $transforms, array $claims): array
    {
        if ($sourceType === MappingSourceType::Static) {
            return ['present' => true, 'value' => $this->transformations->transform($sourceValue, $transforms)];
        }
        $resolved = $this->claims->resolve($claims, $sourceValue);
        return [
            'present' => $resolved->present,
            'value' => $resolved->present ? $this->transformations->transform($resolved->value, $transforms) : null,
        ];
    }
}
