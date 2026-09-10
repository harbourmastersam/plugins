<?php

namespace HarbourmasterSam\ServerLifecycle\Models;

use App\Models\BackupHost;
use HarbourmasterSam\ServerLifecycle\Enums\FinalDeliveryMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class LifecyclePolicy extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'is_default' => 'boolean', 'running_counts_as_active' => 'boolean', 'final_delivery_mode' => FinalDeliveryMode::class];
    }

    protected static function booted(): void
    {
        static::saving(function (self $policy): void {
            $host = BackupHost::query()->find($policy->archive_backup_host_id);
            if (! $host || $host->getAttribute('type') !== 's3') {
                throw ValidationException::withMessages(['archive_backup_host_id' => __('server-lifecycle::strings.errors.s3_only')]);
            }
            if ($policy->is_default) {
                self::query()->whereKeyNot($policy->getKey())->update(['is_default' => false]);
            }
        });
    }

    public function archiveBackupHost(): BelongsTo { return $this->belongsTo(BackupHost::class, 'archive_backup_host_id'); }
    public function warningRules(): HasMany { return $this->hasMany(LifecycleWarningRule::class, 'policy_id')->orderBy('sort'); }
}
