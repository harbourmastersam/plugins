<?php

namespace HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\ServerArchives;

use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\ServerArchives\Pages\ListServerArchives;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Services\Archive\PermanentDeleteArchiveService;
use App\Models\Server;
use App\Services\Servers\ServerDeletionService;
use HarbourmasterSam\ServerLifecycle\Services\Restore\StartRestoreService;
use Illuminate\Support\Number;

class ServerArchiveResource extends Resource
{
    protected static ?string $model = ServerArchive::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-archive';

    protected static string|\UnitEnum|null $navigationGroup = 'Server Lifecycle';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('server_name')->searchable()->sortable(),
            TextColumn::make('owner.email')->label('Owner'),
            TextColumn::make('status')->badge(),
            TextColumn::make('archived_at')->dateTime()->sortable(),
            TextColumn::make('bytes')->formatStateUsing(fn ($state) => $state ? Number::fileSize($state) : '—'),
            TextColumn::make('backupHost.name')->label('Storage host'),
            TextColumn::make('retention_expires_at')->dateTime(),
            TextColumn::make('last_error')->wrap(),
        ])->recordActions([
            Action::make('download')
                ->label('Download')
                ->icon('tabler-download')
                ->url(fn (ServerArchive $record) => route('server-lifecycle.archives.download', $record))
                ->openUrlInNewTab(),
            Action::make('restore')->icon('tabler-restore')->requiresConfirmation()->action(fn (ServerArchive $record, StartRestoreService $service) => $service->handle($record)),
            Action::make('extend_retention')->icon('tabler-calendar-plus')->requiresConfirmation()->action(fn (ServerArchive $record) => $record->update(['retention_expires_at' => $record->retention_expires_at?->addMonth()])),
            Action::make('discard_failed_restore')
                ->label('Discard failed restore server')
                ->icon('tabler-server-off')
                ->color('warning')
                ->visible(fn (ServerArchive $record): bool => $record->status === LifecycleStatus::RestoreFailed && $record->restored_server_id !== null)
                ->requiresConfirmation()
                ->modalDescription('This uses Pelican server deletion for the failed destination. The archive object is retained for a fresh retry.')
                ->action(function (ServerArchive $record, ServerDeletionService $deletion): void {
                    $server = Server::query()->find($record->restored_server_id);
                    if ($server) {
                        $deletion->handle($server);
                    }
                    $record->update(['restored_server_id' => null, 'restore_backup_id' => null]);
                }),
            Action::make('delete_permanently')
                ->label('Delete Permanently')
                ->icon('tabler-trash')
                ->color('danger')
                ->visible(fn (ServerArchive $record): bool => in_array($record->status, [LifecycleStatus::Archived, LifecycleStatus::ArchiveSuperseded, LifecycleStatus::DeletionWarning, LifecycleStatus::Restored, LifecycleStatus::RestoreFailed, LifecycleStatus::PendingDeletion, LifecycleStatus::DeleteFailed], true))
                ->requiresConfirmation()
                ->modalHeading(fn (ServerArchive $record): string => "Permanently delete $record->server_name?")
                ->modalDescription('This deletes the exact archive object and cannot be undone. Download it first if a copy is required.')
                ->action(fn (ServerArchive $record, PermanentDeleteArchiveService $service) => $service->handleManual($record, auth()->user())),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListServerArchives::route('/')];
    }
}
