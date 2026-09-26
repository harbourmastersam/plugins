<?php

namespace HarbourmasterSam\UserAttributeMapper\Models;

use Illuminate\Database\Eloquent\Model;

class DiscoveredClaim extends Model
{
    public const CREATED_AT = null;
    public const UPDATED_AT = null;
    protected $table = 'user_attribute_discovered_claims';
    protected $fillable = ['provider', 'claim_path', 'claim_type', 'first_seen_at', 'last_seen_at', 'observation_count'];
    protected function casts(): array { return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'observation_count' => 'integer']; }
}
