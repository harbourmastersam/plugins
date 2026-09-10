<?php

namespace HarbourmasterSam\ServerLifecycle\Models;

use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerLifecycleState extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['automatic_enabled' => 'boolean', 'is_exempt' => 'boolean', 'last_activity_at' => 'immutable_datetime', 'archive_due_at' => 'immutable_datetime', 'exempt_until' => 'immutable_datetime', 'status' => LifecycleStatus::class]; }
    public function server(): BelongsTo { return $this->belongsTo(Server::class); }
    public function policy(): BelongsTo { return $this->belongsTo(LifecyclePolicy::class, 'policy_id'); }
    public function archive(): BelongsTo { return $this->belongsTo(ServerArchive::class, 'current_archive_id'); }
}
