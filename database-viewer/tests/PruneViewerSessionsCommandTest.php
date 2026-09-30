<?php

namespace GreyHarbour\DatabaseViewer\Tests;

use GreyHarbour\DatabaseViewer\Providers\DatabaseViewerPluginProvider;
use GreyHarbour\DatabaseViewer\Services\ViewerSessionManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Mockery;

class PruneViewerSessionsCommandTest extends TestCase
{
    use DatabaseTruncation;

    public function createApplication()
    {
        $app = require getenv('PELICAN_PATH').'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->register(DatabaseViewerPluginProvider::class);
        $app->make('migrator')->path(dirname(__DIR__).'/database/migrations');

        return $app;
    }

    public function test_command_is_registered_and_loops_bounded_batches_to_completion(): void
    {
        $manager = Mockery::mock(ViewerSessionManager::class);
        $manager->shouldReceive('pruneBatch')->with(500)->times(3)->andReturn(500, 2, 0);
        $this->app->instance(ViewerSessionManager::class, $manager);

        $this->assertArrayHasKey('database-viewer:prune-sessions', Artisan::all());
        $exit = Artisan::call('database-viewer:prune-sessions');

        $this->assertSame(0, $exit);
        $this->assertSame("Pruned 502 Database Viewer sessions.\n", Artisan::output());
    }

    public function test_zero_row_command_reports_only_the_count(): void
    {
        $manager = Mockery::mock(ViewerSessionManager::class);
        $manager->shouldReceive('pruneBatch')->with(500)->once()->andReturn(0);
        $this->app->instance(ViewerSessionManager::class, $manager);

        Artisan::call('database-viewer:prune-sessions');

        $this->assertSame("Pruned 0 Database Viewer sessions.\n", Artisan::output());
    }
}
