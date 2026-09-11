<?php

namespace HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies\Pages;

use App\Enums\TablerIcon;
use App\Traits\Filament\CanCustomizeHeaderActions;
use App\Traits\Filament\CanCustomizeHeaderWidgets;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\CreateRecord;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies\LifecyclePolicyResource;
use HarbourmasterSam\ServerLifecycle\Services\Policy\DurationNormalizer;

class CreateLifecyclePolicy extends CreateRecord
{
    use CanCustomizeHeaderActions;
    use CanCustomizeHeaderWidgets;

    protected static string $resource = LifecyclePolicyResource::class;

    protected static bool $canCreateAnother = false;

    /** @return array<Action|ActionGroup> */
    protected function getDefaultHeaderActions(): array
    {
        return [
            Action::make('create')
                ->hiddenLabel()
                ->action('create')
                ->keyBindings(['mod+s'])
                ->tooltip(
                    trans('filament-panels::resources/pages/create-record.form.actions.create.label')
                )
                ->icon(TablerIcon::FilePlus),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->normalize($data);
    }

    private function normalize(array $data): array
    {
        $duration = app(DurationNormalizer::class);
        $data['inactivity_minutes'] = $duration->toMinutes($data['inactivity_value'] ?? null, $data['inactivity_unit']);
        $data['archive_retention_minutes'] = $duration->toMinutes($data['retention_value'] ?? null, $data['retention_unit']);
        $data['final_delivery_grace_minutes'] = $duration->toMinutes($data['grace_value'], $data['grace_unit']);
        unset($data['inactivity_value'], $data['inactivity_unit'], $data['retention_value'], $data['retention_unit'], $data['grace_value'], $data['grace_unit']);

        return $data;
    }
}
