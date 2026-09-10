<?php

namespace HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies;

use App\Models\BackupHost;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use HarbourmasterSam\ServerLifecycle\Enums\FinalDeliveryMode;
use HarbourmasterSam\ServerLifecycle\Enums\WarningPhase;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies\Pages\CreateLifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies\Pages\EditLifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies\Pages\ListLifecyclePolicies;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;

class LifecyclePolicyResource extends Resource
{
    protected static ?string $model = LifecyclePolicy::class;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-clock-shield';

    protected static string|\UnitEnum|null $navigationGroup = 'Server Lifecycle';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Toggle::make('enabled'),
            Toggle::make('is_default'),
            Select::make('archive_backup_host_id')
                ->label('S3 archive BackupHost')
                ->options(fn () => BackupHost::query()->where('schema', 's3')->pluck('name', 'id'))
                ->required()
                ->searchable(),
            TextInput::make('inactivity_minutes')->numeric()->minValue(1)->nullable()->suffix('minutes'),
            Toggle::make('running_counts_as_active')->default(true),
            TextInput::make('archive_retention_minutes')->numeric()->minValue(1)->nullable()->suffix('minutes'),
            Select::make('final_delivery_mode')->options(collect(FinalDeliveryMode::cases())->mapWithKeys(fn ($mode) => [$mode->value => str($mode->value)->headline()]))->required(),
            TextInput::make('final_delivery_grace_minutes')->numeric()->minValue(1)->required()->suffix('minutes'),
            TextInput::make('attachment_max_bytes')->numeric()->minValue(1)->required()->suffix('bytes'),
            Repeater::make('warningRules')
                ->relationship()
                ->schema([
                    Select::make('phase')->options(collect(WarningPhase::cases())->mapWithKeys(fn ($phase) => [$phase->value => str($phase->value)->headline()]))->required(),
                    TextInput::make('offset_minutes')->numeric()->minValue(1)->required()->suffix('minutes'),
                    Toggle::make('database_enabled')->default(true),
                    Toggle::make('email_enabled'),
                    TextInput::make('sort')->numeric()->default(0),
                ])
                ->columns(5),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            IconColumn::make('enabled')->boolean(),
            IconColumn::make('is_default')->boolean(),
            TextColumn::make('archiveBackupHost.name')->label('Archive host'),
            TextColumn::make('inactivity_minutes')->suffix(' min'),
            TextColumn::make('archive_retention_minutes')->suffix(' min'),
        ])->recordActions([EditAction::make(), DeleteAction::make()])->toolbarActions([CreateAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLifecyclePolicies::route('/'),
            'create' => CreateLifecyclePolicy::route('/create'),
            'edit' => EditLifecyclePolicy::route('/{record}/edit'),
        ];
    }
}
