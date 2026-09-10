<?php

namespace HarbourmasterSam\ServerLifecycle\Filament\Admin\Resources\Servers\RelationManagers;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use HarbourmasterSam\ServerLifecycle\Services\Policy\ConfigureServerLifecycleService;
use HarbourmasterSam\ServerLifecycle\Services\Policy\PolicyResolver;
use HarbourmasterSam\ServerLifecycle\Services\Archive\StartArchiveService;
use Illuminate\Support\Carbon;

/** @method Server getOwnerRecord() */
class LifecycleRelationManager extends RelationManager
{
    protected static string $relationship = 'lifecycleState';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Lifecycle configuration')
            ->columns([
                TextColumn::make('policy.name')->default('Default policy'),
                IconColumn::make('automatic_enabled')->boolean(),
                IconColumn::make('is_exempt')->boolean(),
                TextColumn::make('exempt_until')->dateTime(),
                TextColumn::make('last_activity_at')->dateTime(),
                TextColumn::make('archive_due_at')->dateTime(),
                TextColumn::make('status')->badge(),
            ])
            ->headerActions([
                Action::make('archive_now')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The server must be confirmed Offline. The same fail-closed archive workflow is used.')
                    ->action(function (StartArchiveService $archive, PolicyResolver $policies): void {
                        $state = ServerLifecycleState::query()->where('server_id', $this->getOwnerRecord()->id)->firstOrFail();
                        $policy = $policies->resolve($state);
                        abort_unless($policy, 422);
                        $archive->handle($this->getOwnerRecord(), $policy);
                    }),
                Action::make('reset_activity')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $state = ServerLifecycleState::query()->where('server_id', $this->getOwnerRecord()->id)->firstOrFail();
                        $policy = $state->policy ?: LifecyclePolicy::query()->where('enabled', true)->where('is_default', true)->first();
                        $now = now();
                        $state->update([
                            'last_activity_at' => $now,
                            'last_activity_event' => 'server:lifecycle.admin-reset',
                            'archive_due_at' => $state->automatic_enabled && ! $state->is_exempt && $policy?->inactivity_minutes !== null
                                ? $now->copy()->addMinutes($policy->inactivity_minutes)
                                : null,
                        ]);
                    }),
                Action::make('configure')
                    ->schema([
                        Select::make('policy_id')
                            ->options(LifecyclePolicy::query()->where('enabled', true)->pluck('name', 'id'))
                            ->placeholder('Default policy'),
                        Toggle::make('automatic_enabled'),
                        Toggle::make('is_exempt')->label('Permanent exemption'),
                        DateTimePicker::make('exempt_until')->label('Temporary exemption until'),
                    ])
                    ->fillForm(function (): array {
                        $state = ServerLifecycleState::query()->where('server_id', $this->getOwnerRecord()->id)->first();

                        return [
                            'policy_id' => $state?->policy_id,
                            'automatic_enabled' => $state?->automatic_enabled ?? false,
                            'is_exempt' => $state?->is_exempt ?? false,
                            'exempt_until' => $state?->exempt_until,
                        ];
                    })
                    ->action(function (array $data, ConfigureServerLifecycleService $service): void {
                        $policy = isset($data['policy_id']) ? LifecyclePolicy::query()->find($data['policy_id']) : null;
                        $service->handle(
                            $this->getOwnerRecord(),
                            $policy,
                            (bool) $data['automatic_enabled'],
                            (bool) $data['is_exempt'],
                            isset($data['exempt_until']) ? Carbon::parse($data['exempt_until']) : null,
                        );
                    }),
            ]);
    }
}
