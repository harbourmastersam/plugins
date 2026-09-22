<?php
namespace Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages;
use Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\AttributeMappingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
class EditAttributeMapping extends EditRecord { protected static string $resource = AttributeMappingResource::class; protected function getHeaderActions(): array { return [DeleteAction::make()]; } }
