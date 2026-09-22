<?php

namespace Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings;

use Boy132\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use Boy132\UserAttributeMapper\Enums\MissingClaimBehavior;
use Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\CreateAttributeMapping;
use Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\EditAttributeMapping;
use Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ListAttributeMappings;
use Boy132\UserAttributeMapper\Models\AttributeMapping;
use Boy132\UserAttributeMapper\OAuth\OAuthProviderResolver;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttributeMappingResource extends Resource
{
    protected static ?string $model = AttributeMapping::class;
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-arrows-exchange';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('provider')->required()->searchable()->options(function (?AttributeMapping $record): array {
                $options = ['*' => 'All OAuth providers'] + app(OAuthProviderResolver::class)->options();
                if ($record && !isset($options[$record->provider])) $options[$record->provider] = $record->provider.' (unavailable)';
                return $options;
            }),
            TextInput::make('source_claim')->required()->maxLength(512)->helperText('Dot-separated raw claim path, for example entitlements.max_widgets.'),
            Select::make('target_attribute')->required()->searchable()->options(function (?AttributeMapping $record): array {
                $groups = app(UserAttributeRegistryContract::class)->grouped()->map(fn ($items) => $items->mapWithKeys(fn ($item) => [$item->key => $item->label])->all())->all();
                if ($record && app(UserAttributeRegistryContract::class)->get($record->target_attribute) === null) $groups['Unavailable'][$record->target_attribute] = $record->target_attribute.' (unavailable)';
                return $groups;
            })->rules([fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                $target = app(UserAttributeRegistryContract::class)->get((string) $value);
                if ($target === null || !$target->writableFromIdentity) $fail('The target must be a currently registered, identity-writable attribute.');
            }]),
            Select::make('missing_claim_behavior')->required()->options(['preserve' => 'Preserve existing value', 'clear' => 'Clear when supported'])->default(MissingClaimBehavior::Preserve->value),
            TextInput::make('priority')->numeric()->minValue(0)->default(100)->required(),
            Toggle::make('enabled')->default(true),
            Textarea::make('description')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('provider')->badge(), TextColumn::make('source_claim')->searchable(),
            TextColumn::make('target_attribute')->formatStateUsing(fn (string $state) => app(UserAttributeRegistryContract::class)->get($state)?->label ?? "$state (unavailable)")->description(fn (AttributeMapping $record) => app(UserAttributeRegistryContract::class)->get($record->target_attribute)?->owner),
            IconColumn::make('enabled')->boolean(), TextColumn::make('missing_claim_behavior')->badge(),
        ])->recordActions([EditAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAttributeMappings::route('/'), 'create' => CreateAttributeMapping::route('/create'), 'edit' => EditAttributeMapping::route('/{record}/edit')];
    }
}
