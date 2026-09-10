<?php

namespace HarbourmasterSam\ServerLifecycle\Providers;

use App\Enums\HeaderActionPosition;
use App\Events\ActivityLogged;
use App\Events\Server\BackupCompleted;
use App\Events\Server\Installed;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Filament\Admin\Resources\Servers\ServerResource as AdminServerResource;
use App\Models\Role;
use App\Models\Server;
use Filament\Actions\Action;
use HarbourmasterSam\ServerLifecycle\Console\Commands\EvaluateLifecycleCommand;
use HarbourmasterSam\ServerLifecycle\Filament\App\Resources\ServerArchives\ServerArchiveResource;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\Servers\RelationManagers\LifecycleRelationManager;
use HarbourmasterSam\ServerLifecycle\Listeners\CompleteLifecycleBackup;
use HarbourmasterSam\ServerLifecycle\Listeners\CompleteLifecycleRestore;
use HarbourmasterSam\ServerLifecycle\Listeners\ContinueRestoreAfterInstall;
use HarbourmasterSam\ServerLifecycle\Listeners\TrackServerActivity;
use HarbourmasterSam\ServerLifecycle\Storage\ArchiveStorageInterface;
use HarbourmasterSam\ServerLifecycle\Storage\S3ArchiveStorage;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

class ServerLifecyclePluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ArchiveStorageInterface::class, S3ArchiveStorage::class);
        AdminServerResource::registerCustomRelations(LifecycleRelationManager::class);
        foreach (['lifecyclePolicy', 'serverArchive', 'serverLifecycle'] as $permission) Role::registerCustomDefaultPermissions($permission);
        Role::registerCustomModelIcon('serverArchive', 'tabler-archive');
        ListServers::registerCustomHeaderActions(
            HeaderActionPosition::After,
            Action::make('archived_servers')
                ->label(__('server-lifecycle::strings.archives.title'))
                ->icon('tabler-archive')
                ->url(fn (): string => ServerArchiveResource::getUrl(panel: 'app')),
        );
    }
    public function boot(): void
    {
        Server::resolveRelationUsing('lifecycleState', fn (Server $server) => $server->hasOne(ServerLifecycleState::class, 'server_id'));
        $this->loadViewsFrom(plugin_path('server-lifecycle', 'resources/views'), 'server-lifecycle');
        Event::listen(ActivityLogged::class, TrackServerActivity::class);
        Event::listen(ActivityLogged::class, CompleteLifecycleRestore::class);
        Event::listen(BackupCompleted::class, CompleteLifecycleBackup::class);
        Event::listen(Installed::class, ContinueRestoreAfterInstall::class);
        Schedule::command(EvaluateLifecycleCommand::class)->everyMinute()->withoutOverlapping();
        $this->loadRoutesFrom(plugin_path('server-lifecycle', 'routes/web.php'));
    }
}
