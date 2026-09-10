<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Archive;

use App\Enums\ContainerStatus;
use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\RetryDeletionDecision;

class RetryDeletionEligibilityService
{
    public function decide(Server $server): RetryDeletionDecision
    {
        // Transport failures are deliberately not caught: callers retain the
        // retryable state and apply backoff without doing anything destructive.
        $status = $server->retrieveStatus();

        return in_array($status, [ContainerStatus::Offline, ContainerStatus::Missing], true)
            ? RetryDeletionDecision::ContinueCleanup
            : RetryDeletionDecision::RevokeAuthority;
    }
}
