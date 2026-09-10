<?php

namespace HarbourmasterSam\ServerLifecycle\Models;

use HarbourmasterSam\ServerLifecycle\Enums\WarningPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LifecycleWarningRule extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['phase' => WarningPhase::class, 'database_enabled' => 'boolean', 'email_enabled' => 'boolean']; }
    public function policy(): BelongsTo { return $this->belongsTo(LifecyclePolicy::class, 'policy_id'); }
}
