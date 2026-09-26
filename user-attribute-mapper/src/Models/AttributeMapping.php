<?php

namespace HarbourmasterSam\UserAttributeMapper\Models;

use HarbourmasterSam\UserAttributeMapper\Enums\MissingClaimBehavior;
use HarbourmasterSam\UserAttributeMapper\Enums\MappingSourceType;
use Illuminate\Database\Eloquent\Model;

class AttributeMapping extends Model
{
    protected $table = 'user_attribute_mappings';

    protected $fillable = ['provider', 'source_type', 'source_value', 'target_attribute', 'enabled', 'missing_claim_behavior', 'priority', 'description', 'transforms'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'priority' => 'integer', 'transforms' => 'array', 'source_type' => MappingSourceType::class, 'missing_claim_behavior' => MissingClaimBehavior::class];
    }
}
