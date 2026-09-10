<?php

namespace HarbourmasterSam\ServerLifecycle\Models;

use App\Models\BackupHost;
use App\Models\User;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerArchive extends Model
{
    use HasUuids;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['id'];
    protected $hidden = ['manifest'];
    protected function casts(): array
    {
        return ['status' => LifecycleStatus::class, 'manifest' => 'encrypted:array', 'policy_snapshot' => 'array', 'original_node' => 'array', 'original_allocations' => 'array', 'archived_at' => 'immutable_datetime', 'retention_expires_at' => 'immutable_datetime', 'pending_deletion_at' => 'immutable_datetime', 'final_delivery_sent_at' => 'immutable_datetime', 'final_delivery_expires_at' => 'immutable_datetime', 'restored_at' => 'immutable_datetime', 'remote_object_deleted_at' => 'immutable_datetime'];
    }
    public function owner(): BelongsTo { return $this->belongsTo(User::class); }
    public function backupHost(): BelongsTo { return $this->belongsTo(BackupHost::class); }
    public function scopeVisibleTo($query, User $user)
    {
        return $user->root_admin ? $query : $query->where('owner_id', $user->id);
    }
}
