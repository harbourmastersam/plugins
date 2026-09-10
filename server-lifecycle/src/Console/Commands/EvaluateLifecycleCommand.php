<?php

namespace HarbourmasterSam\ServerLifecycle\Console\Commands;

use HarbourmasterSam\ServerLifecycle\Services\Policy\EvaluateLifecycleService;
use Illuminate\Console\Command;

class EvaluateLifecycleCommand extends Command
{
    protected $signature = 'p:server-lifecycle:evaluate';

    protected $description = 'Evaluate due server lifecycle work and dispatch idempotent jobs.';

    public function handle(EvaluateLifecycleService $service): int
    {
        if (config('server-lifecycle.enabled')) {
            $service->handle();
        }

        return self::SUCCESS;
    }
}
