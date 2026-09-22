<?php

namespace Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages;

use Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\AttributeMappingResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAttributeMapping extends EditRecord
{
    protected static string $resource = AttributeMappingResource::class;

    protected function getFormActions(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->getCancelFormAction()->formId('form')
                ->tooltip(fn (Action $action) => $action->getLabel())
                ->hiddenLabel()
                ->icon('tabler-arrow-left'),
            DeleteAction::make(),
            $this->getSaveFormAction()->formId('form')
                ->tooltip(fn (Action $action) => $action->getLabel())
                ->hiddenLabel()
                ->icon('tabler-device-floppy'),
        ];
    }
}
