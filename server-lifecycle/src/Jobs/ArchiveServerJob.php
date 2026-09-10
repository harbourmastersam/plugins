<?php

namespace HarbourmasterSam\ServerLifecycle\Jobs;

use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\LifecycleStatus;
use HarbourmasterSam\ServerLifecycle\Models\ServerLifecycleState;
use HarbourmasterSam\ServerLifecycle\Services\Archive\StartArchiveService;
use HarbourmasterSam\ServerLifecycle\Services\Policy\PolicyResolver;
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

    public function handle(StartArchiveService $archive, PolicyResolver $policies): void
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

        $archive->handle($server, $policy);
    }
}
