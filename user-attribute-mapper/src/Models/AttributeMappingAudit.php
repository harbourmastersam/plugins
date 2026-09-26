<?php

namespace HarbourmasterSam\UserAttributeMapper\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeMappingAudit extends Model
{
    public const UPDATED_AT = null;
    protected $table = 'user_attribute_mapping_audits';
    protected $fillable = ['actor_id', 'provider', 'action', 'changes'];
    protected function casts(): array { return ['changes' => 'array', 'created_at' => 'datetime']; }
}
