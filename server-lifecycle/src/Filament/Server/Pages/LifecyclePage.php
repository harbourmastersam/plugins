<?php

namespace HarbourmasterSam\ServerLifecycle\Filament\Server\Pages;

use App\Models\Server;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use HarbourmasterSam\ServerLifecycle\Services\Archive\StartArchiveService;
use HarbourmasterSam\ServerLifecycle\Services\Policy\PolicyResolver;

class LifecyclePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'tabler-clock-shield';

    protected static ?string $navigationLabel = 'Lifecycle';

    protected static ?string $title = 'Server Lifecycle';

    protected string $view = 'server-lifecycle::filament.server.pages.lifecycle';

    public ServerLifecycleState $lifecycleState;

    public function mount(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        $this->lifecycleState = ServerLifecycleState::query()->firstOrCreate(
            ['server_id' => $server->id],
            ['automatic_enabled' => false, 'last_activity_at' => now(), 'status' => LifecycleStatus::Active],
        );
    }

    protected function getHeaderActions(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return [
            Action::make('reset_activity')
                ->label('Reset inactivity timer')
                ->icon('tabler-refresh')
                ->authorize(fn (): bool => (int) $server->owner_id === (int) auth()->id())
                ->action(function (PolicyResolver $policies): void {
                    $policy = $policies->resolve($this->lifecycleState);
                    $this->lifecycleState->update([
                        'last_activity_at' => now(),
                        'last_activity_event' => 'server:lifecycle.manual-reset',
                        'archive_due_at' => $policy?->inactivity_minutes === null ? null : now()->addMinutes($policy->inactivity_minutes),
                        'status' => LifecycleStatus::Active,
                    ]);
                    $this->lifecycleState->refresh();
                }),
            Action::make('archive_now')
                ->label('Archive now')
                ->icon('tabler-archive')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => (bool) config('server-lifecycle.users_may_archive'))
                ->authorize(fn (): bool => (int) $server->owner_id === (int) auth()->id())
                ->action(function (StartArchiveService $service, PolicyResolver $policies) use ($server): void {
                    $policy = $policies->resolve($this->lifecycleState);
                    abort_unless($policy, 422);
                    $service->handle($server, $policy);
                    Notification::make()->title('Archive backup started')->success()->send();
                }),
        ];
    }
}
