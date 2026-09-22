<?php
namespace Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\Pages;
use Boy132\UserAttributeMapper\Filament\Admin\Resources\AttributeMappings\AttributeMappingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
class ListAttributeMappings extends ListRecords { protected static string $resource = AttributeMappingResource::class; protected function getHeaderActions(): array { return [CreateAction::make()]; } }
