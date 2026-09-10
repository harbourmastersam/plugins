<?php
namespace HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies\Pages;
use Filament\Resources\Pages\CreateRecord;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies\LifecyclePolicyResource;
class CreateLifecyclePolicy extends CreateRecord { protected static string $resource = LifecyclePolicyResource::class; }
