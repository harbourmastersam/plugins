<?php

namespace HarbourmasterSam\ServerLifecycle\Jobs;

use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\AuthoritativeServerState;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use HarbourmasterSam\ServerLifecycle\Services\Archive\StartArchiveService;
use HarbourmasterSam\ServerLifecycle\Services\Policy\PolicyResolver;
use HarbourmasterSam\ServerLifecycle\Services\Status\FreshWingsServerStatusService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ArchiveServerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(public int $serverId) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    public function handle(StartArchiveService $archive, PolicyResolver $policies, FreshWingsServerStatusService $statuses): void
    {
        $server = Server::query()->findOrFail($this->serverId);
        $state = ServerLifecycleState::query()->where('server_id', $server->id)->firstOrFail();
        $policy = $policies->resolve($state);

        if (! config('server-lifecycle.enabled')
            || ! $state->automatic_enabled
            || $state->is_exempt
            || ! $policy
            || ! in_array($state->status, [LifecycleStatus::Active, LifecycleStatus::Warning], true)
            || ! $state->archive_due_at?->isPast()
            || $state->exempt_until?->isFuture()) {
            return;
        }

        // Poll only an automatically due server. A transport exception leaves
        // activity untouched and fails closed; Missing is likewise not activity.
        try {
            $status = $statuses->get($server);
        } catch (\Throwable) {
            return;
        }
        if ($status === AuthoritativeServerState::Active) {
            $observedAt = now();
            $state->update([
                'last_activity_at' => $observedAt,
                'last_activity_event' => 'server:lifecycle.observed-active',
                'archive_due_at' => $policy->inactivity_minutes === null
                    ? null
                    : $observedAt->copy()->addMinutes($policy->inactivity_minutes),
                'status' => LifecycleStatus::Active,
            ]);
            return;
        }
        if ($status !== AuthoritativeServerState::Offline) return;

        $archive->handle($server, $policy);
    }
}
