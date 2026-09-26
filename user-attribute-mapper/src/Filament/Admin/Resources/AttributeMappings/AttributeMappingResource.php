<?php

namespace HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings;

use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use HarbourmasterSam\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages\ManageAttributeMappings;
use HarbourmasterSam\UserAttributeMapper\Models\AttributeMapping;
use Filament\Resources\Resource;
use Illuminate\Support\Collection;

class AttributeMappingResource extends Resource
{
    protected static ?string $model = AttributeMapping::class;
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-arrows-exchange';

    public static function getNavigationLabel(): string
    {
        return 'User Profile Mappings';
    }

    public static function targetAttributeOptions(UserAttributeRegistryContract $registry, ?AttributeMapping $record = null): array
    {
        $groups = $registry->writableFromIdentity()
            ->groupBy(fn ($definition) => $definition->group ?? $definition->owner)
            ->map(fn (Collection $items) => $items->mapWithKeys(fn ($item) => [$item->key => $item->label])->all())
            ->all();

        if ($record && $registry->writableFromIdentity()->get($record->target_attribute) === null) {
            $definition = $registry->get($record->target_attribute);
            $label = $definition?->label ?? $record->target_attribute;
            $groups['Unavailable'][$record->target_attribute] = $label.($definition ? ' (read-only)' : ' (unavailable)');
        }

        return $groups;
    }

    public static function getPages(): array
    {
        return ['index' => ManageAttributeMappings::route('/')];
    }
}
