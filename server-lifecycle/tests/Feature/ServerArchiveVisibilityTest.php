<?php

use App\Models\BackupHost;
use App\Models\User;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\ServerArchives\ServerArchiveResource as AdminServerArchiveResource;
use HarbourmasterSam\ServerLifecycle\Filament\App\Resources\ServerArchives\ServerArchiveResource as AppServerArchiveResource;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('excludes deleted tombstones only when the not-deleted scope is requested', function (): void {
    $archives = archiveVisibilityFixtures(User::factory()->create());

    $visibleIds = ServerArchive::query()->notDeleted()->pluck('id');

    expect($visibleIds)
        ->toContain($archives['archived']->id, $archives['restored']->id, $archives['delete_failed']->id)
        ->not->toContain($archives['deleted']->id)
        ->and(ServerArchive::query()->whereKey($archives['deleted']->id)->exists())->toBeTrue();
});

it('excludes deleted tombstones from the admin resource query', function (): void {
    $archives = archiveVisibilityFixtures(User::factory()->create());

    $visibleIds = AdminServerArchiveResource::getEloquentQuery()->pluck('id');

    expect($visibleIds)
        ->toContain($archives['archived']->id, $archives['restored']->id, $archives['delete_failed']->id)
        ->not->toContain($archives['deleted']->id)
        ->and(ServerArchive::query()->whereKey($archives['deleted']->id)->exists())->toBeTrue();
});

it('excludes deleted tombstones without weakening client ownership scope', function (): void {
    $owner = User::factory()->create();
    $archives = archiveVisibilityFixtures($owner);
    $otherArchive = archiveVisibilityRecord(User::factory()->create(), LifecycleStatus::Archived);
    $this->actingAs($owner);

    $visibleIds = AppServerArchiveResource::getEloquentQuery()->pluck('id');

    expect($visibleIds)
        ->toContain($archives['archived']->id, $archives['restored']->id, $archives['delete_failed']->id)
        ->not->toContain($archives['deleted']->id, $otherArchive->id)
        ->and(ServerArchive::query()->whereKey($archives['deleted']->id)->exists())->toBeTrue();
});

/** @return array{archived: ServerArchive, restored: ServerArchive, delete_failed: ServerArchive, deleted: ServerArchive} */
function archiveVisibilityFixtures(User $owner): array
{
    return [
        'archived' => archiveVisibilityRecord($owner, LifecycleStatus::Archived),
        'restored' => archiveVisibilityRecord($owner, LifecycleStatus::Restored),
        'delete_failed' => archiveVisibilityRecord($owner, LifecycleStatus::DeleteFailed),
        'deleted' => archiveVisibilityRecord($owner, LifecycleStatus::Deleted),
    ];
}

function archiveVisibilityRecord(User $owner, LifecycleStatus $status): ServerArchive
{
    $serverUuid = fake()->uuid();
    $backupUuid = fake()->uuid();

    return ServerArchive::query()->create([
        'owner_id' => $owner->id,
        'original_server_uuid' => $serverUuid,
        'original_uuid_short' => substr($serverUuid, 0, 8),
        'server_name' => "{$status->value} archive",
        'backup_host_id' => BackupHost::factory()->create(['schema' => 's3'])->id,
        'object_key' => $status === LifecycleStatus::Deleted ? null : "$serverUuid/$backupUuid.tar.gz",
        'original_backup_uuid' => $backupUuid,
        'status' => $status,
        'policy_snapshot' => [],
        'remote_object_deleted_at' => $status === LifecycleStatus::Deleted ? now() : null,
        'deletion_reason' => $status === LifecycleStatus::Deleted ? 'manual_owner' : null,
    ]);
}
