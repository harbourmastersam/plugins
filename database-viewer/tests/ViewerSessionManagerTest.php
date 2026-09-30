<?php

namespace GreyHarbour\DatabaseViewer\Tests;

use App\Models\Database;
use App\Models\DatabaseHost;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;
use GreyHarbour\DatabaseViewer\Exceptions\ViewerSessionException;
use GreyHarbour\DatabaseViewer\Models\ViewerSession;
use GreyHarbour\DatabaseViewer\Providers\DatabaseViewerPluginProvider;
use GreyHarbour\DatabaseViewer\Services\ViewerSessionManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class ViewerSessionManagerTest extends TestCase
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

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $server = Server::factory()->create();
        $database = Database::factory()->create([
            'server_id' => $server->id,
            'database_host_id' => DatabaseHost::factory()->create()->id,
        ]);

        return [$server->user, $server, $database];
    }

    public function test_migration_has_authoritative_session_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('database_viewer_sessions', [
            'id', 'user_id', 'server_id', 'database_id', 'channel_hash', 'created_at',
            'expires_at', 'last_activity_at', 'revoked_at',
        ]));
        $columns = Schema::getColumnListing('database_viewer_sessions');
        foreach (['channel', 'token', 'sql', 'prompt', 'password', 'access_mode'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
        $indexes = array_map(fn (object $index): string => $index->name, DB::select("PRAGMA index_list('database_viewer_sessions')"));
        foreach ([
            'database_viewer_sessions_channel_hash_unique',
            'database_viewer_sessions_active_user_index',
            'database_viewer_sessions_expires_at_index',
            'database_viewer_sessions_revoked_at_index',
        ] as $required) {
            $this->assertContains($required, $indexes);
        }
    }

    public function test_create_stores_only_hash_and_exact_fifteen_minute_lease(): void
    {
        CarbonImmutable::setTestNow('2026-09-30 12:00:00 UTC');
        [$user, $server, $database] = $this->fixture();

        $handle = app(ViewerSessionManager::class)->create($user, $server, $database);
        $row = ViewerSession::query()->sole();

        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D', $handle->channel);
        $this->assertSame(hash('sha256', $handle->channel), $row->channel_hash);
        $this->assertNotSame($handle->channel, $row->channel_hash);
        $this->assertTrue($handle->serverNow->equalTo(CarbonImmutable::now()));
        $this->assertTrue($handle->expiresAt->equalTo(CarbonImmutable::now()->addMinutes(15)));
        $this->assertTrue($handle->maxExpiresAt->equalTo(CarbonImmutable::now()->addHours(2)));
        $this->assertTrue($row->last_activity_at->equalTo(CarbonImmutable::now()));
    }

    public function test_two_viewers_have_independent_identities(): void
    {
        [$user, $server, $database] = $this->fixture();
        $manager = app(ViewerSessionManager::class);

        $first = $manager->create($user, $server, $database);
        $second = $manager->create($user, $server, $database);

        $this->assertNotSame($first->channel, $second->channel);
        $this->assertCount(2, ViewerSession::all());
    }

    public function test_twenty_first_active_viewer_is_rejected(): void
    {
        [$user, $server, $database] = $this->fixture();
        $manager = app(ViewerSessionManager::class);
        foreach (range(1, 20) as $_) {
            $manager->create($user, $server, $database);
        }

        try {
            $manager->create($user, $server, $database);
            $this->fail('Twenty-first active viewer was created.');
        } catch (ViewerSessionException $exception) {
            $this->assertSame(429, $exception->httpStatus());
            $this->assertSame('VIEWER_LIMIT_REACHED', $exception->applicationCode());
        }
        $this->assertSame(20, ViewerSession::query()->count());
    }

    public function test_validation_checks_every_binding_and_does_not_slide_expiry(): void
    {
        CarbonImmutable::setTestNow('2026-09-30 12:00:00 UTC');
        [$user, $server, $database] = $this->fixture();
        $manager = app(ViewerSessionManager::class);
        $handle = $manager->create($user, $server, $database);
        $originalExpiry = $handle->expiresAt;
        CarbonImmutable::setTestNow('2026-09-30 12:05:00 UTC');

        $session = $manager->validate($handle->channel, $user->id, $server->id, $database->id);
        $this->assertTrue($session->fresh()->last_activity_at->equalTo(CarbonImmutable::now()));
        $this->assertTrue($session->fresh()->expires_at->equalTo($originalExpiry));

        foreach ([
            ['x'.substr($handle->channel, 1), $user->id, $server->id, $database->id],
            [$handle->channel, $user->id + 1000, $server->id, $database->id],
            [$handle->channel, $user->id, $server->id + 1000, $database->id],
            [$handle->channel, $user->id, $server->id, $database->id + 1000],
        ] as $arguments) {
            try {
                $manager->validate(...$arguments);
                $this->fail('Mismatched viewer session validated.');
            } catch (ViewerSessionException $exception) {
                $this->assertSame(403, $exception->httpStatus());
            }
        }
    }

    public function test_expired_and_revoked_sessions_have_distinct_safe_codes(): void
    {
        CarbonImmutable::setTestNow('2026-09-30 12:00:00 UTC');
        [$user, $server, $database] = $this->fixture();
        $manager = app(ViewerSessionManager::class);
        $expired = $manager->create($user, $server, $database);
        CarbonImmutable::setTestNow('2026-09-30 12:15:00 UTC');
        try {
            $manager->validate($expired->channel, $user->id, $server->id, $database->id);
            $this->fail('Expired viewer validated.');
        } catch (ViewerSessionException $exception) {
            $this->assertSame(410, $exception->httpStatus());
            $this->assertSame('SESSION_EXPIRED', $exception->applicationCode());
        }

        CarbonImmutable::setTestNow('2026-09-30 12:16:00 UTC');
        $closed = $manager->create($user, $server, $database);
        $manager->close($closed->channel, $user->id, $server->id, $database->id);
        try {
            $manager->validate($closed->channel, $user->id, $server->id, $database->id);
            $this->fail('Closed viewer validated.');
        } catch (ViewerSessionException $exception) {
            $this->assertSame('SESSION_CLOSED', $exception->applicationCode());
        }
    }

    public function test_extend_uses_now_plus_fifteen_minutes_without_stacking_and_caps_at_two_hours(): void
    {
        CarbonImmutable::setTestNow('2026-09-30 12:00:00 UTC');
        [$user, $server, $database] = $this->fixture();
        $manager = app(ViewerSessionManager::class);
        $handle = $manager->create($user, $server, $database);

        CarbonImmutable::setTestNow('2026-09-30 12:05:00 UTC');
        $extended = $manager->extend($handle->channel, $user->id, $server->id, $database->id);
        $this->assertTrue($extended->serverNow->equalTo(CarbonImmutable::now()));
        $this->assertTrue($extended->expiresAt->equalTo(CarbonImmutable::now()->addMinutes(15)));

        ViewerSession::query()->update(['expires_at' => CarbonImmutable::now()->addHour()]);
        $notStacked = $manager->extend($handle->channel, $user->id, $server->id, $database->id);
        $this->assertTrue($notStacked->expiresAt->equalTo(CarbonImmutable::now()->addMinutes(15)));

        CarbonImmutable::setTestNow('2026-09-30 13:50:00 UTC');
        ViewerSession::query()->update(['expires_at' => CarbonImmutable::now()->addMinutes(5)]);
        $capped = $manager->extend($handle->channel, $user->id, $server->id, $database->id);
        $this->assertTrue($capped->expiresAt->equalTo(CarbonImmutable::parse('2026-09-30 14:00:00 UTC')));
    }

    public function test_expired_or_closed_session_cannot_be_extended(): void
    {
        CarbonImmutable::setTestNow('2026-09-30 12:00:00 UTC');
        [$user, $server, $database] = $this->fixture();
        $manager = app(ViewerSessionManager::class);
        $expired = $manager->create($user, $server, $database);
        CarbonImmutable::setTestNow('2026-09-30 12:15:00 UTC');
        try {
            $manager->extend($expired->channel, $user->id, $server->id, $database->id);
            $this->fail('Expired viewer was extended.');
        } catch (ViewerSessionException $exception) {
            $this->assertSame('SESSION_EXPIRED', $exception->applicationCode());
        }

        CarbonImmutable::setTestNow('2026-09-30 12:16:00 UTC');
        $closed = $manager->create($user, $server, $database);
        $manager->close($closed->channel, $user->id, $server->id, $database->id);
        try {
            $manager->extend($closed->channel, $user->id, $server->id, $database->id);
            $this->fail('Closed viewer was extended.');
        } catch (ViewerSessionException $exception) {
            $this->assertSame('SESSION_CLOSED', $exception->applicationCode());
        }
    }

    public function test_close_is_idempotent_and_isolated(): void
    {
        [$user, $server, $database] = $this->fixture();
        $manager = app(ViewerSessionManager::class);
        $first = $manager->create($user, $server, $database);
        $second = $manager->create($user, $server, $database);

        $manager->close($first->channel, $user->id, $server->id, $database->id);
        $firstRevokedAt = ViewerSession::query()->where('channel_hash', hash('sha256', $first->channel))->value('revoked_at');
        $manager->close($first->channel, $user->id, $server->id, $database->id);

        $this->assertEquals($firstRevokedAt, ViewerSession::query()->where('channel_hash', hash('sha256', $first->channel))->value('revoked_at'));
        $this->assertNull(ViewerSession::query()->where('channel_hash', hash('sha256', $second->channel))->value('revoked_at'));
        $this->assertNotNull($manager->validate($second->channel, $user->id, $server->id, $database->id));
    }
}
