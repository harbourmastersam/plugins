<?php

namespace GreyHarbour\DatabaseViewer\Console\Commands;

use GreyHarbour\DatabaseViewer\Services\ViewerSessionManager;
use Illuminate\Console\Command;

class PruneViewerSessionsCommand extends Command
{
    protected $signature = 'database-viewer:prune-sessions';

    protected $description = 'Prune expired and closed Database Viewer sessions after retention';

    public function handle(ViewerSessionManager $sessions): int
    {
        $total = 0;
        do {
            $pruned = $sessions->pruneBatch(500);
            $total += $pruned;
        } while ($pruned > 0);

        $this->line("Pruned {$total} Database Viewer sessions.");

        return self::SUCCESS;
    }
}
