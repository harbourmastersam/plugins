<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\AuthoritativeServerState;
use HarbourmasterSam\ServerLifecycle\Enums\RetryDeletionDecision;
use HarbourmasterSam\ServerLifecycle\Services\Status\FreshWingsServerStatusService;

class RetryDeletionEligibilityService
{
    public function __construct(private FreshWingsServerStatusService $statuses) {}

    public function decide(Server $server): RetryDeletionDecision
    {
        // Transport failures are deliberately not caught: callers retain the
        // retryable state and apply backoff without doing anything destructive.
        $status = $this->statuses->get($server);

        if (in_array($status, [AuthoritativeServerState::Offline, AuthoritativeServerState::ConfirmedMissing], true)) {
            return RetryDeletionDecision::ContinueCleanup;
        }
        if ($status === AuthoritativeServerState::Active) return RetryDeletionDecision::RevokeAuthority;
        throw new \RuntimeException('Wings state is not safe for deletion retry.');
    }
}
