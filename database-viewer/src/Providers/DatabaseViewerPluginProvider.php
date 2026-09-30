<?php

namespace GreyHarbour\DatabaseViewer\Providers;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Databases\DatabaseResource;
use App\Models\Database;
use Filament\Actions\Action;
use Filament\Tables\Table;
use GreyHarbour\DatabaseViewer\Services\DatabaseResultSerializer;
use GreyHarbour\DatabaseViewer\Services\MariaDbExecutor;
use GreyHarbour\DatabaseViewer\Services\QueryExecutor;
use GreyHarbour\DatabaseViewer\Console\Commands\PruneViewerSessionsCommand;
use Illuminate\Support\ServiceProvider;

class DatabaseViewerPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/database-viewer.php', 'database-viewer');
        $this->app->singleton(DatabaseResultSerializer::class);
        $this->app->bind(QueryExecutor::class, MariaDbExecutor::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'database-viewer');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([PruneViewerSessionsCommand::class]);
        }

        DatabaseResource::modifyTable(fn (Table $table): Table => $table->pushRecordActions([
            Action::make('databaseViewer')
                ->label('Open Database Viewer')
                ->icon('tabler-database')
                ->visible(fn (Database $record): bool => auth()->user()?->canAccessTenant($record->server)
                    && auth()->user()->can(SubuserPermission::DatabaseRead, $record->server)
                    && !$record->server->isInConflictState())
                ->url(fn (Database $record): string => route('database-viewer.show', [
                    'server' => $record->server->uuid_short,
                    'database' => $record->id,
                ]))
                ->openUrlInNewTab(),
        ]));
    }
}
