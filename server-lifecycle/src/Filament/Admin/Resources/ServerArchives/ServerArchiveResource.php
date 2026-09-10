<?php

namespace HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\ServerArchives;

use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\ServerArchives\Pages\ListServerArchives;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use HarbourmasterSam\ServerLifecycle\Services\Archive\PermanentDeleteArchiveService;
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
            Action::make('download')->icon('tabler-download')->url(fn (ServerArchive $record) => route('server-lifecycle.archives.download', $record)),
            Action::make('restore')->requiresConfirmation()->action(fn (ServerArchive $record, StartRestoreService $service) => $service->handle($record)),
            Action::make('extend_retention')->requiresConfirmation()->action(fn (ServerArchive $record) => $record->update(['retention_expires_at' => $record->retention_expires_at?->addMonth()])),
            Action::make('delete_permanently')
                ->label('Delete Permanently')
                ->color('danger')
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
