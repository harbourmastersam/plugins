<?php

namespace HarbourmasterSam\ServerLifecycle\Filament\App\Resources\ServerArchives;

use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Filament\App\Resources\ServerArchives\Pages\ListServerArchives;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Services\Archive\PermanentDeleteArchiveService;
use HarbourmasterSam\ServerLifecycle\Services\Restore\StartRestoreService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class ServerArchiveResource extends Resource
{
    protected static ?string $model = ServerArchive::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-archive';

    protected static ?string $navigationLabel = 'Archived Servers';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->visibleTo(auth()->user())
            ->notDeleted();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('server_name')->searchable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('archived_at')->dateTime(),
            TextColumn::make('bytes')->formatStateUsing(fn ($state) => $state ? Number::fileSize($state) : '—'),
            TextColumn::make('retention_expires_at')->dateTime(),
            TextColumn::make('original_allocations')->formatStateUsing(fn ($state) => collect($state)->pluck('port')->join(', '))->label('Preferred ports'),
        ])->recordActions([
            Action::make('download')
                ->label('Download')
                ->icon('tabler-download')
                ->visible(fn () => config('server-lifecycle.users_may_download'))
                ->url(fn (ServerArchive $record) => route('server-lifecycle.archives.download', $record))
                ->openUrlInNewTab(),
            Action::make('restore')
                ->icon('tabler-restore')
                ->visible(fn () => config('server-lifecycle.users_may_restore'))
                ->requiresConfirmation()
                ->action(function (ServerArchive $record, StartRestoreService $service): void {
                    abort_unless((int) $record->owner_id === (int) auth()->id(), 403);
                    $service->handle($record);
                }),
            Action::make('delete_permanently')
                ->label('Delete Permanently')
                ->icon('tabler-trash')
                ->color('danger')
                ->visible(fn (ServerArchive $record): bool => (bool) config('server-lifecycle.users_may_delete')
                    && in_array($record->status, [LifecycleStatus::Archived, LifecycleStatus::ArchiveSuperseded, LifecycleStatus::DeletionWarning, LifecycleStatus::Restored, LifecycleStatus::RestoreFailed, LifecycleStatus::PendingDeletion, LifecycleStatus::DeleteFailed], true))
                ->requiresConfirmation()
                ->modalDescription('This deletes the archive permanently and cannot be undone. Download it before continuing if you need a copy.')
                ->action(function (ServerArchive $record, PermanentDeleteArchiveService $service): void {
                    $service->handleManual($record, auth()->user());
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListServerArchives::route('/')];
    }
}
