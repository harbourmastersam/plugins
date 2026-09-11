<?php

use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Tables\Table;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies\Pages\CreateLifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\LifecyclePolicies\Pages\EditLifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\Servers\RelationManagers\LifecycleRelationManager;

it('gives every lifecycle relation manager action an icon', function (): void {
    $server = Server::factory()->create();

    $relationManager = new LifecycleRelationManager();
    $relationManager->ownerRecord = $server;
    $relationManager->pageClass = EditServer::class;

    $table = $relationManager->table(Table::make($relationManager));
    $actions = collect($table->getHeaderActions())->keyBy(fn (Action $action): string => $action->getName());

    expect($actions->keys()->all())->toContain('archive_now', 'reset_activity', 'configure')
        ->and($actions['archive_now']->getIcon())->toBe('tabler-archive')
        ->and($actions['reset_activity']->getIcon())->toBe('tabler-refresh')
        ->and($actions['configure']->getIcon())->toBe('tabler-settings');
});

it('provides an icon-backed create policy header action that invokes create', function (): void {
    $page = new class extends CreateLifecyclePolicy
    {
        public function defaultHeaderActions(): array
        {
            return $this->getDefaultHeaderActions();
        }

        public function formActions(): array
        {
            return $this->getFormActions();
        }

        public function normalizeForCreate(array $data): array
        {
            return $this->mutateFormDataBeforeCreate($data);
        }
    };

    $action = collect($page->defaultHeaderActions())->first(fn (Action $action): bool => $action->getName() === 'create');

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->getIcon())->not->toBeNull()
        ->and($action->getActionFunction())->toBe('create')
        ->and($page->formActions())->toBe([])
        ->and($page->normalizeForCreate(uiCompatibilityDurationFormData()))->toMatchArray([
            'inactivity_minutes' => 120,
            'archive_retention_minutes' => 10080,
            'final_delivery_grace_minutes' => 30,
        ]);
});

it('provides an icon-backed edit policy header action that invokes save', function (): void {
    $page = new class extends EditLifecyclePolicy
    {
        public function defaultHeaderActions(): array
        {
            return $this->getDefaultHeaderActions();
        }

        public function formActions(): array
        {
            return $this->getFormActions();
        }

        public function normalizeForSave(array $data): array
        {
            return $this->mutateFormDataBeforeSave($data);
        }
    };

    $action = collect($page->defaultHeaderActions())->first(fn (Action $action): bool => $action->getName() === 'save');

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->getIcon())->not->toBeNull()
        ->and($action->getActionFunction())->toBe('save')
        ->and($page->formActions())->toBe([])
        ->and($page->normalizeForSave(uiCompatibilityDurationFormData()))->toMatchArray([
            'inactivity_minutes' => 120,
            'archive_retention_minutes' => 10080,
            'final_delivery_grace_minutes' => 30,
        ]);
});

function uiCompatibilityDurationFormData(): array
{
    return [
        'name' => 'Compatibility policy',
        'inactivity_value' => 2,
        'inactivity_unit' => 'hours',
        'retention_value' => 7,
        'retention_unit' => 'days',
        'grace_value' => 30,
        'grace_unit' => 'minutes',
    ];
}
