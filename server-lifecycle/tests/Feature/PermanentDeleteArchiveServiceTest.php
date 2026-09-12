<?php

use App\Models\BackupHost;
use App\Models\Role;
use App\Models\User;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Http\Controllers\ArchiveDownloadController;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Services\Archive\PermanentDeleteArchiveService;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('allows a root admin to invoke permanent deletion for another users archive', function (): void {
    config()->set('server-lifecycle.users_may_delete', true);
    $owner = User::factory()->create();
    $rootAdmin = rootAdminUser();
    $archive = deletableArchive($owner);
    bindSuccessfulArchiveDeletion($archive);
    $this->actingAs($rootAdmin);

    $action = serverArchiveResourceActions()['delete_permanently'];
    app()->call($action->getActionFunction(), [
        'record' => $archive,
        'service' => app(PermanentDeleteArchiveService::class),
    ]);

    expect($rootAdmin->isRootAdmin())->toBeTrue()
        ->and($archive->fresh()->status)->toBe(LifecycleStatus::Deleted)
        ->and($archive->fresh()->deletion_reason)->toBe('manual_admin');
});

it('allows an owner to delete when user deletion is enabled', function (): void {
    config()->set('server-lifecycle.users_may_delete', true);
    $owner = User::factory()->create();
    $archive = deletableArchive($owner);
    bindSuccessfulArchiveDeletion($archive);

    app(PermanentDeleteArchiveService::class)->handleManual($archive, $owner);

    expect($archive->fresh()->status)->toBe(LifecycleStatus::Deleted)
        ->and($archive->fresh()->deletion_reason)->toBe('manual_owner');
});

it('allows a root admin when user deletion is disabled', function (): void {
    config()->set('server-lifecycle.users_may_delete', false);
    $archive = deletableArchive(User::factory()->create());
    $rootAdmin = rootAdminUser();
    bindSuccessfulArchiveDeletion($archive);

    app(PermanentDeleteArchiveService::class)->handleManual($archive, $rootAdmin);

    expect($rootAdmin->isRootAdmin())->toBeTrue()
        ->and($archive->fresh()->status)->toBe(LifecycleStatus::Deleted)
        ->and($archive->fresh()->deletion_reason)->toBe('manual_admin');
});

it('denies an ordinary non-owner', function (): void {
    config()->set('server-lifecycle.users_may_delete', true);
    $archive = deletableArchive(User::factory()->create());

    expect(fn () => app(PermanentDeleteArchiveService::class)->handleManual($archive, User::factory()->create()))
        ->toThrow(HttpException::class, '', 403);
});

it('denies an owner when user deletion is disabled', function (): void {
    config()->set('server-lifecycle.users_may_delete', false);
    $owner = User::factory()->create();
    $archive = deletableArchive($owner);

    expect(fn () => app(PermanentDeleteArchiveService::class)->handleManual($archive, $owner))
        ->toThrow(HttpException::class, '', 403);
});

it('denies a non-root admin without archive delete permission from deleting another users archive', function (): void {
    config()->set('server-lifecycle.users_may_delete', true);
    $archive = deletableArchive(User::factory()->create());
    $admin = User::factory()->create();
    $admin->assignRole(Role::query()->create(['name' => 'archive-test-admin']));

    expect($admin->isRootAdmin())->toBeFalse()
        ->and(fn () => app(PermanentDeleteArchiveService::class)->handleManual($archive, $admin))
        ->toThrow(HttpException::class, '', 403);
});

it('allows a delegated admin with archive delete permission to delete another users archive', function (): void {
    config()->set('server-lifecycle.users_may_delete', false);
    $archive = deletableArchive(User::factory()->create());
    $admin = User::factory()->create();
    $role = Role::query()->create([
        'name' => 'Archive Administrator',
        'guard_name' => Role::DEFAULT_GUARD_NAME,
    ]);
    $permission = Permission::firstOrCreate([
        'name' => 'delete serverArchive',
        'guard_name' => Role::DEFAULT_GUARD_NAME,
    ]);
    $role->givePermissionTo($permission);
    $admin->assignRole($role);
    bindSuccessfulArchiveDeletion($archive);

    expect($admin->isRootAdmin())->toBeFalse()
        ->and($admin->can('delete serverArchive'))->toBeTrue();

    app(PermanentDeleteArchiveService::class)->handleManual($archive, $admin);

    expect($archive->fresh()->status)->toBe(LifecycleStatus::Deleted)
        ->and($archive->fresh()->deletion_reason)->toBe('manual_admin');
});

it('records an authorized owner with archive delete permission as an admin deletion', function (): void {
    config()->set('server-lifecycle.users_may_delete', false);
    $owner = User::factory()->create();
    $role = Role::query()->create([
        'name' => 'Archive Owner Administrator',
        'guard_name' => Role::DEFAULT_GUARD_NAME,
    ]);
    $permission = Permission::firstOrCreate([
        'name' => 'delete serverArchive',
        'guard_name' => Role::DEFAULT_GUARD_NAME,
    ]);
    $role->givePermissionTo($permission);
    $owner->assignRole($role);
    $archive = deletableArchive($owner);
    bindSuccessfulArchiveDeletion($archive);

    app(PermanentDeleteArchiveService::class)->handleManual($archive, $owner);

    expect($archive->fresh()->status)->toBe(LifecycleStatus::Deleted)
        ->and($archive->fresh()->deletion_reason)->toBe('manual_admin');
});

it('deletes only the exact archive object and clears its key after confirmation', function (): void {
    config()->set('server-lifecycle.users_may_delete', true);
    $owner = User::factory()->create();
    $archive = deletableArchive($owner);
    $expectedKey = "{$archive->original_server_uuid}/{$archive->original_backup_uuid}.tar.gz";
    $storage = Mockery::mock(ArchiveStorageInterface::class);
    $storage->shouldReceive('delete')->once()->ordered()->withArgs(function (ServerArchive $deleting) use ($expectedKey): bool {
        return $deleting->object_key === $expectedKey && $deleting->status === LifecycleStatus::Deleting;
    });
    $storage->shouldReceive('exists')->once()->ordered()->withArgs(function (ServerArchive $checking) use ($expectedKey): bool {
        return $checking->object_key === $expectedKey;
    })->andReturnFalse();
    app()->instance(ArchiveStorageInterface::class, $storage);

    app(PermanentDeleteArchiveService::class)->handleManual($archive, $owner);

    $deleted = $archive->fresh();
    expect($deleted->status)->toBe(LifecycleStatus::Deleted)
        ->and($deleted->remote_object_deleted_at)->not->toBeNull()
        ->and($deleted->object_key)->toBeNull();
});

it('keeps a failed remote deletion retryable', function (): void {
    config()->set('server-lifecycle.users_may_delete', true);
    $owner = User::factory()->create();
    $archive = deletableArchive($owner);
    $storage = Mockery::mock(ArchiveStorageInterface::class);
    $storage->shouldReceive('delete')->once()->andThrow(new RuntimeException('storage unavailable'));
    app()->instance(ArchiveStorageInterface::class, $storage);

    expect(fn () => app(PermanentDeleteArchiveService::class)->handleManual($archive, $owner))
        ->toThrow(RuntimeException::class, 'storage unavailable');

    $failed = $archive->fresh();
    expect($failed->status)->toBe(LifecycleStatus::DeleteFailed)
        ->and($failed->object_key)->not->toBeNull()
        ->and($failed->retry_after)->not->toBeNull();
});

function deletableArchive(User $owner): ServerArchive
{
    $serverUuid = fake()->uuid();
    $backupUuid = fake()->uuid();

    return ServerArchive::create([
        'owner_id' => $owner->id,
        'original_server_uuid' => $serverUuid,
        'original_uuid_short' => substr($serverUuid, 0, 8),
        'server_name' => 'Archived server',
        'backup_host_id' => BackupHost::factory()->create(['schema' => 's3'])->id,
        'object_key' => "$serverUuid/$backupUuid.tar.gz",
        'original_backup_uuid' => $backupUuid,
        'status' => LifecycleStatus::Archived,
        'policy_snapshot' => [],
    ]);
}

function bindSuccessfulArchiveDeletion(ServerArchive $archive): void
{
    $storage = Mockery::mock(ArchiveStorageInterface::class);
    $storage->shouldReceive('delete')->once()->with($archive);
    $storage->shouldReceive('exists')->once()->with($archive)->andReturnFalse();
    app()->instance(ArchiveStorageInterface::class, $storage);
}

function rootAdminUser(): User
{
    $rootAdmin = User::factory()->create();
    $rootAdmin->assignRole(Role::getRootAdmin());

    return $rootAdmin;
}

it('allows an owner to download through the authenticated controller route', function (): void {
    config()->set('server-lifecycle.users_may_download', true);
    $owner = User::factory()->create();
    $archive = deletableArchive($owner);
    $storage = downloadableArchiveStorage($archive);
    $this->actingAs($owner);

    $response = app(ArchiveDownloadController::class)($archive, $storage);

    expect($response->getTargetUrl())->toBe('https://archive.example.test/presigned');
});

it('allows a root admin to download another users archive', function (): void {
    config()->set('server-lifecycle.users_may_download', false);
    $archive = deletableArchive(User::factory()->create());
    $storage = downloadableArchiveStorage($archive);
    $rootAdmin = rootAdminUser();
    $this->actingAs($rootAdmin);

    expect($rootAdmin->isRootAdmin())->toBeTrue()
        ->and(app(ArchiveDownloadController::class)($archive, $storage)->getTargetUrl())
        ->toBe('https://archive.example.test/presigned');
});

it('denies an ordinary non-owner download', function (): void {
    config()->set('server-lifecycle.users_may_download', true);
    $archive = deletableArchive(User::factory()->create());
    $this->actingAs(User::factory()->create());

    expect(fn () => app(ArchiveDownloadController::class)($archive, Mockery::mock(ArchiveStorageInterface::class)))
        ->toThrow(HttpException::class, '', 403);
});

it('rejects a download in a non-downloadable lifecycle state', function (): void {
    config()->set('server-lifecycle.users_may_download', true);
    $owner = User::factory()->create();
    $archive = deletableArchive($owner);
    $archive->update(['status' => LifecycleStatus::Deleting]);
    $this->actingAs($owner);

    expect(fn () => app(ArchiveDownloadController::class)($archive, Mockery::mock(ArchiveStorageInterface::class)))
        ->toThrow(HttpException::class, '', 410);
});

it('rejects a download whose remote object is missing', function (): void {
    config()->set('server-lifecycle.users_may_download', true);
    $owner = User::factory()->create();
    $archive = deletableArchive($owner);
    $storage = Mockery::mock(ArchiveStorageInterface::class);
    $storage->shouldReceive('exists')->once()->with($archive)->andReturnFalse();
    $this->actingAs($owner);

    expect(fn () => app(ArchiveDownloadController::class)($archive, $storage))
        ->toThrow(HttpException::class, '', 404);
});

function downloadableArchiveStorage(ServerArchive $archive): ArchiveStorageInterface
{
    $storage = Mockery::mock(ArchiveStorageInterface::class);
    $storage->shouldReceive('exists')->once()->with($archive)->andReturnTrue();
    $storage->shouldReceive('temporaryDownloadUrl')->once()->with($archive, Mockery::type(\Carbon\CarbonInterval::class))
        ->andReturn('https://archive.example.test/presigned');

    return $storage;
}
