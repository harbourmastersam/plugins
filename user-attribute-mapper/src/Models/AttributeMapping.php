<?php

namespace Boy132\UserAttributeMapper\Models;

use Boy132\UserAttributeMapper\Enums\MissingClaimBehavior;
use Illuminate\Database\Eloquent\Model;

class AttributeMapping extends Model
{
    protected $table = 'user_attribute_mappings';

    protected $fillable = ['provider', 'source_claim', 'target_attribute', 'enabled', 'missing_claim_behavior', 'priority', 'description'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'priority' => 'integer', 'missing_claim_behavior' => MissingClaimBehavior::class];
    }
}
