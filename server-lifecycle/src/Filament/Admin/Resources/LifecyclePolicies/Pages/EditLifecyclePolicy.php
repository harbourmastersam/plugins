<?php

namespace HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies\Pages;

use App\Enums\TablerIcon;
use App\Traits\Filament\CanCustomizeHeaderActions;
use App\Traits\Filament\CanCustomizeHeaderWidgets;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies\LifecyclePolicyResource;
use HarbourmasterSam\ServerLifecycle\Services\Policy\DurationNormalizer;

class EditLifecyclePolicy extends EditRecord
{
    use CanCustomizeHeaderActions;
    use CanCustomizeHeaderWidgets;

    protected static string $resource = LifecyclePolicyResource::class;

    /** @return array<Action|ActionGroup> */
    protected function getDefaultHeaderActions(): array
    {
        return [
            DeleteAction::make(),

            Action::make('save')
                ->hiddenLabel()
                ->action('save')
                ->keyBindings(['mod+s'])
                ->tooltip(
                    trans('filament-panels::resources/pages/edit-record.form.actions.save.label')
                )
                ->icon(TablerIcon::DeviceFloppy),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $duration = app(DurationNormalizer::class);
        foreach (['inactivity' => 'inactivity_minutes', 'retention' => 'archive_retention_minutes', 'grace' => 'final_delivery_grace_minutes'] as $prefix => $column) {
            $parts = $duration->fromMinutes($data[$column] ?? null);
            $data[$prefix.'_value'] = $parts['value'];
            $data[$prefix.'_unit'] = $parts['unit'];
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $duration = app(DurationNormalizer::class);
        $data['inactivity_minutes'] = $duration->toMinutes($data['inactivity_value'] ?? null, $data['inactivity_unit']);
        $data['archive_retention_minutes'] = $duration->toMinutes($data['retention_value'] ?? null, $data['retention_unit']);
        $data['final_delivery_grace_minutes'] = $duration->toMinutes($data['grace_value'], $data['grace_unit']);
        unset($data['inactivity_value'], $data['inactivity_unit'], $data['retention_value'], $data['retention_unit'], $data['grace_value'], $data['grace_unit']);

        return $data;
    }
}
