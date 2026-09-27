<?php

namespace GreyHarbour\DatabaseViewer\Tests;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Databases\DatabaseResource;
use App\Filament\Server\Resources\Databases\Pages\ListDatabases;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Database;
use App\Models\DatabaseHost;
use App\Models\Role;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use GreyHarbour\DatabaseViewer\Providers\DatabaseViewerPluginProvider;
use GreyHarbour\DatabaseViewer\Services\MariaDbExecutor;
use GreyHarbour\DatabaseViewer\Services\QueryExecutor;
use GreyHarbour\DatabaseViewer\Services\ViewerContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Context;
use PHPUnit\Framework\Attributes\DataProvider;

class ViewerTest extends TestCase
{
    // Pelican's exception handler rolls back transactions; transaction-wrapped
    // fixtures disappear after a denied HTTP request. Match its truncation convention.
    use DatabaseTruncation;

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // HTTP tests share an application; real HTTP requests have separate Context scopes.
        Context::flush();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function createApplication()
    {
        $app = require getenv('PELICAN_PATH').'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->register(DatabaseViewerPluginProvider::class);
        $app['router']->getRoutes()->refreshNameLookups();
        $app['router']->getRoutes()->refreshActionLookups();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'panel.auth.2fa_required' => 0]);
    }

    private function fixture(): array
    {
        $server = Server::factory()->create();
        $database = Database::factory()->create(['server_id' => $server->id, 'database_host_id' => DatabaseHost::factory()->create()->id, 'password' => 'NEVER-EXPOSE-THIS']);

        return [$server->user, $server, $database];
    }

    private function url(Server $server, Database $database, bool $query = false): string
    {
        return '/database-viewer/servers/'.$server->uuid_short.'/databases/'.$database->id.($query ? '/query' : '');
    }

    private function open(User $user, Server $server, Database $database): string
    {
        return $this->actingAs($user)->get($this->url($server, $database))->assertOk()->viewData('channel');
    }

    public function test_owner_can_open_fresh_credential_free_viewers(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $a = $this->open($owner, $server, $database);
        $b = $this->open($owner, $server, $database);
        $this->assertNotSame($a, $b);
        $this->actingAs($owner)->get($this->url($server, $database))
            ->assertViewHas('iframeUrl', fn ($url) => str_contains($url, '&mode=probe'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $a);
        $this->actingAs($owner)->get($this->url($server, $database))->assertDontSee('NEVER-EXPOSE-THIS')->assertDontSee($database->username)->assertSee('sandbox="allow-scripts allow-same-origin"', false)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_unrelated_user_and_subuser_without_database_read_are_denied(): void
    {
        [, $server, $database] = $this->fixture();
        $user = User::factory()->create();
        $this->actingAs($user)->get($this->url($server, $database))->assertForbidden();
        Subuser::create(['user_id' => $user->id, 'server_id' => $server->id, 'permissions' => []]);
        $this->actingAs($user)->get($this->url($server, $database))->assertForbidden();
    }

    public function test_read_permission_is_sufficient_without_view_password_permission(): void
    {
        [, $server, $database] = $this->fixture();
        $user = User::factory()->create();
        Subuser::create(['user_id' => $user->id, 'server_id' => $server->id, 'permissions' => [SubuserPermission::DatabaseRead->value]]);
        $this->open($user, $server, $database);
    }

    public function test_foreign_database_and_deleted_database_are_denied(): void
    {
        [$owner, $server, $database] = $this->fixture();
        [, , $foreign] = $this->fixture();
        $this->actingAs($owner)->get($this->url($server, $foreign))->assertNotFound();
        $this->actingAs($owner)->postJson($this->url($server, $foreign, true), [])->assertNotFound();
        $channel = $this->open($owner, $server, $database);
        $database->delete();
        $this->postJson($this->url($server, $database, true), compact('channel') + ['statement' => 'SELECT 1'])->assertNotFound();
    }

    public function test_broker_requires_authentication_and_rechecks_revoked_permissions(): void
    {
        [, $server, $database] = $this->fixture();
        $this->postJson($this->url($server, $database, true))->assertUnauthorized();
        $user = User::factory()->create();
        $subuser = Subuser::create(['user_id' => $user->id, 'server_id' => $server->id, 'permissions' => [SubuserPermission::DatabaseRead->value]]);
        $channel = $this->open($user, $server, $database);
        $subuser->update(['permissions' => []]);
        $this->postJson($this->url($server, $database, true), compact('channel') + ['statement' => 'SELECT 1'])->assertForbidden();
    }

    public function test_channel_is_bound_to_user_server_and_database(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        $other = Database::factory()->create(['server_id' => $server->id, 'database_host_id' => $database->database_host_id]);
        $this->postJson($this->url($server, $other, true), compact('channel') + ['statement' => 'SELECT 1'])->assertForbidden();
        $this->postJson($this->url($server, $database, true), ['channel' => str_repeat('z', 43), 'statement' => 'SELECT 1'])->assertForbidden();
        $user = User::factory()->create();
        Subuser::create(['user_id' => $user->id, 'server_id' => $server->id, 'permissions' => [SubuserPermission::DatabaseRead->value]]);
        $this->actingAs($user)->postJson($this->url($server, $database, true), compact('channel') + ['statement' => 'SELECT 1'])->assertForbidden();
    }

    public static function forbiddenSql(): array
    {
        return array_map(fn ($s) => [$s], ['SELECT 2', 'SELECT 1; DROP TABLE x', 'SELECT 1 -- comment', 'SHOW TABLES', 'INSERT INTO x VALUES (1)', 'SELECT 01', 'SELECT 1;;', 'BEGIN']);
    }

    #[DataProvider('forbiddenSql')]
    public function test_arbitrary_sql_never_reaches_executor(string $statement): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        $this->mock(QueryExecutor::class)->shouldNotReceive('execute');
        $this->postJson($this->url($server, $database, true), compact('channel', 'statement'))->assertStatus(422)->assertExactJson(['error' => 'Query not permitted in MVP mode.']);
    }

    public function test_select_one_returns_studio_shape_and_safe_errors(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        $this->mock(QueryExecutor::class)->shouldReceive('execute')->once()->withArgs(fn ($db) => $db->id === $database->id)->andReturn(2.5);
        $this->postJson($this->url($server, $database, true), compact('channel') + ['statement' => " SELECT 1; \n"])->assertOk()->assertExactJson(['data' => ['headers' => [['name' => '1', 'displayName' => '1', 'originalType' => 'INT', 'type' => 2]], 'rows' => [['1' => 1]], 'stat' => ['rowsAffected' => 0, 'rowsRead' => 1, 'rowsWritten' => null, 'queryDurationMs' => 2.5]]]);
        $this->mock(QueryExecutor::class)->shouldReceive('execute')->andThrow(new \RuntimeException('NEVER-EXPOSE-THIS /internal/path'));
        config(['app.debug' => true]);
        $this->postJson($this->url($server, $database, true), compact('channel') + ['statement' => 'SELECT 1'])->assertStatus(503)->assertExactJson(['error' => 'Database query failed.']);
    }

    public function test_root_admin_uses_normal_pelican_access(): void
    {
        [, $server, $database] = $this->fixture();
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => Role::ROOT_ADMIN, 'guard_name' => 'web']);
        $admin->assignRole($role);
        $this->open($admin, $server, $database);
    }

    public function test_csrf_is_enforced_outside_laravels_test_bypass(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        $this->app->bind(PreventRequestForgery::class, EnforcedCsrf::class);
        $payload = ['channel' => $channel, 'statement' => 'SELECT 1'];
        $this->postJson($this->url($server, $database, true), $payload)->assertStatus(419);
        $this->mock(QueryExecutor::class)->shouldReceive('execute')->once()->andReturn(1.0);
        $this->withSession(['_token' => 'test-csrf'])->postJson($this->url($server, $database, true), $payload, ['X-CSRF-TOKEN' => 'test-csrf'])->assertOk();
    }

    public function test_suspended_server_is_denied(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        $server->update(['status' => 'suspended']);
        $this->get($this->url($server, $database))->assertForbidden();
        $this->postJson($this->url($server, $database, true), compact('channel') + ['statement' => 'SELECT 1'])->assertForbidden();
    }

    public function test_context_storage_is_bounded_and_oldest_viewer_fails_closed(): void
    {
        $contexts = new ViewerContext();
        $session = $this->app['session.store'];
        $old = $contexts->create($session, 1, 2, 3);
        for ($i = 0; $i < 20; $i++) {
            $latest = $contexts->create($session, 1, 2, 3);
        }
        $this->assertFalse($contexts->matches($session, $old, 1, 2, 3));
        $this->assertTrue($contexts->matches($session, $latest, 1, 2, 3));
        $this->assertCount(20, $session->get('database-viewer.contexts'));
    }

    public function test_executor_uses_selected_credentials_and_fixed_sql(): void
    {
        [, , $database] = $this->fixture();
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects($this->once())->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn([['1' => '1']]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())->method('query')->with('SELECT 1')->willReturn($statement);
        $connector = $this->createMock(MySqlConnector::class);
        $connector->expects($this->once())->method('connect')->with($this->callback(function ($config) use ($database) {
            return $config['host'] === $database->host->host && $config['port'] === $database->host->port
                && $config['database'] === $database->database && $config['username'] === $database->username
                && $config['password'] === 'NEVER-EXPOSE-THIS'
                && $config['options'][\PDO::ATTR_TIMEOUT] === 3
                && $config['options'][\PDO::MYSQL_ATTR_MULTI_STATEMENTS] === false
                && $config['options'][\PDO::MYSQL_ATTR_INIT_COMMAND] === 'SET SESSION max_statement_time=3';
        }))->willReturn($pdo);
        $this->assertGreaterThanOrEqual(0, (new MariaDbExecutor($connector))->execute($database));
        $this->assertNotSame('NEVER-EXPOSE-THIS', $database->getRawOriginal('password'));
    }

    public function test_executor_rejects_malformed_result(): void
    {
        [, , $database] = $this->fixture();
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn([['1' => 2]]);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('query')->willReturn($statement);
        $connector = $this->createStub(MySqlConnector::class);
        $connector->method('connect')->willReturn($pdo);
        $this->expectException(\RuntimeException::class);
        (new MariaDbExecutor($connector))->execute($database);
    }

    public function test_supported_table_hook_appends_action_and_preserves_core_actions(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('server'));
        Filament::setTenant($server);
        $component = app(ListDatabases::class);
        $table = DatabaseResource::table(Table::make($component));
        $actions = $table->getFlatRecordActions();
        $this->assertArrayHasKey('view', $actions);
        $this->assertArrayHasKey('delete', $actions);
        $this->assertArrayHasKey('databaseViewer', $actions);
        $actions['databaseViewer']->record($database);
        $this->assertTrue($actions['databaseViewer']->isVisible());
        $this->assertStringEndsWith($this->url($server, $database), $actions['databaseViewer']->getUrl());
    }
}

class EnforcedCsrf extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
