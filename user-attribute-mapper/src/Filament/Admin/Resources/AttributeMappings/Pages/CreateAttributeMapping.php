<?php

namespace Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages;

use Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\AttributeMappingResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateAttributeMapping extends CreateRecord
{
    protected static string $resource = AttributeMappingResource::class;

    protected static bool $canCreateAnother = false;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCreateFormAction()->formId('form')
                ->tooltip(fn (Action $action) => $action->getLabel())
                ->hiddenLabel()
                ->icon('tabler-plus'),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
