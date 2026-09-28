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
use GreyHarbour\DatabaseViewer\Enums\AllowedQuery;
use GreyHarbour\DatabaseViewer\Providers\DatabaseViewerPluginProvider;
use GreyHarbour\DatabaseViewer\Services\BrokerLimits;
use GreyHarbour\DatabaseViewer\Services\DatabaseResultSerializer;
use GreyHarbour\DatabaseViewer\Services\MariaDbExecutor;
use GreyHarbour\DatabaseViewer\Services\QueryExecutor;
use GreyHarbour\DatabaseViewer\Services\ViewerContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
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

    private function queryPayload(string $channel, string $statement = 'SELECT 1'): array
    {
        return ['type' => 'query', 'channel' => $channel, 'statement' => $statement];
    }

    private function aiUrl(Server $server, Database $database): string
    {
        return '/database-viewer/servers/'.$server->uuid_short.'/databases/'.$database->id.'/ai';
    }

    private function emptyResult(float $duration = 0): array
    {
        return ['headers' => [], 'rows' => [], 'stat' => ['rowsAffected' => 0, 'rowsRead' => 0, 'rowsWritten' => null, 'queryDurationMs' => $duration]];
    }

    private function schemaStatements(string $database): array
    {
        $literal = "'".str_replace("'", "''", $database)."'";

        return [
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = $literal",
            "SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, DATA_LENGTH, INDEX_LENGTH FROM information_schema.tables WHERE TABLE_SCHEMA = $literal",
            "SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, EXTRA, COLUMN_KEY, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE TABLE_SCHEMA = $literal",
            "SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.table_constraints WHERE TABLE_SCHEMA = $literal AND CONSTRAINT_TYPE IN ('PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY')",
            "SELECT CONSTRAINT_NAME, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.key_column_usage WHERE TABLE_SCHEMA = $literal",
            "SELECT * from information_schema.triggers WHERE TRIGGER_SCHEMA = $literal",
        ];
    }

    public function test_owner_can_open_fresh_credential_free_viewers(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $a = $this->open($owner, $server, $database);
        $b = $this->open($owner, $server, $database);
        $this->assertNotSame($a, $b);
        $this->actingAs($owner)->get($this->url($server, $database))
            ->assertViewHas('iframeUrl', function (string $url) use ($database): bool {
                $query = parse_url($url, PHP_URL_QUERY);
                parse_str($query, $parameters);

                return ($parameters['database'] ?? null) === $database->database
                    && isset($parameters['channel'])
                    && ! isset($parameters['mode'])
                    && substr_count($query, 'database=') === 1;
            });
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $a);
        $this->actingAs($owner)->get($this->url($server, $database))->assertDontSee('NEVER-EXPOSE-THIS')->assertDontSee($database->username)->assertSee('sandbox="allow-scripts allow-same-origin"', false)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_database_query_separator_is_explicit_and_rfc3986_encoded(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $originalSeparator = ini_get('arg_separator.output');
        ini_set('arg_separator.output', '&amp;');

        try {
            $this->actingAs($owner)->get($this->url($server, $database))
                ->assertOk()
                ->assertSee('&amp;database=', false)
                ->assertDontSee('&amp;amp;database=', false);
        } finally {
            ini_set('arg_separator.output', $originalSeparator);
        }
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
        $this->postJson($this->url($server, $database, true), $this->queryPayload($channel, $statement))->assertStatus(422)->assertExactJson(['error' => 'Query not permitted.']);
    }

    public function test_select_one_returns_studio_shape_and_safe_errors(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        $result = ['headers' => [['name' => '1', 'displayName' => '1', 'originalType' => 'INT', 'type' => 2]], 'rows' => [['1' => 1]], 'stat' => ['rowsAffected' => 0, 'rowsRead' => 1, 'rowsWritten' => null, 'queryDurationMs' => 2.5]];
        $this->mock(QueryExecutor::class)->shouldReceive('execute')->once()->withArgs(fn ($db, $operation) => $db->id === $database->id && $operation === AllowedQuery::Diagnostic)->andReturn($result);
        $this->postJson($this->url($server, $database, true), $this->queryPayload($channel, " SELECT 1; \n"))->assertOk()->assertExactJson(['data' => ['headers' => [['name' => '1', 'displayName' => '1', 'originalType' => 'INT', 'type' => 2]], 'rows' => [['1' => 1]], 'stat' => ['rowsAffected' => 0, 'rowsRead' => 1, 'rowsWritten' => null, 'queryDurationMs' => 2.5]]]);
        $this->mock(QueryExecutor::class)->shouldReceive('execute')->andThrow(new \RuntimeException('NEVER-EXPOSE-THIS /internal/path'));
        config(['app.debug' => true]);
        $this->postJson($this->url($server, $database, true), $this->queryPayload($channel))->assertStatus(503)->assertExactJson(['error' => 'Database query failed.']);
    }

    public function test_ai_request_is_reauthorized_and_forwarded_without_exposing_the_broker_token(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        config([
            'database-viewer.ai_token' => 'SERVER-ONLY-AI-TOKEN',
        ]);
        Http::fake([
            'https://studio.greyharbour.net/internal/ai' => Http::response(['response' => "```sql\nSELECT 1\n```"]),
        ]);
        $messages = [
            ['role' => 'system', 'content' => 'Only return SQL'],
            ['role' => 'user', 'content' => 'Test the connection'],
        ];

        $response = $this->postJson($this->aiUrl($server, $database), [
            'type' => 'ai', 'channel' => $channel, 'messages' => $messages,
        ]);

        $response->assertOk()->assertExactJson(['data' => ['response' => "```sql\nSELECT 1\n```"]]);
        Http::assertSent(fn ($request) => $request->url() === 'https://studio.greyharbour.net/internal/ai'
            && $request->hasHeader('Authorization', 'Bearer SERVER-ONLY-AI-TOKEN')
            && $request->data() === ['messages' => $messages]);
        $response->assertDontSee('SERVER-ONLY-AI-TOKEN');
    }

    public function test_ai_request_fails_closed_for_invalid_context_and_envelopes(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        config([
            'database-viewer.ai_token' => 'SERVER-ONLY-AI-TOKEN',
        ]);
        Http::fake();
        $valid = ['role' => 'user', 'content' => 'Count users'];

        $this->postJson($this->aiUrl($server, $database), [
            'type' => 'ai', 'channel' => str_repeat('z', 43), 'messages' => [$valid],
        ])->assertForbidden();

        $invalid = [
            ['channel' => $channel, 'messages' => [$valid]],
            ['type' => 'ai', 'channel' => $channel, 'messages' => []],
            ['type' => 'ai', 'channel' => $channel, 'messages' => array_fill(0, 13, $valid)],
            ['type' => 'ai', 'channel' => $channel, 'messages' => [['role' => 'tool', 'content' => 'bad']]],
            ['type' => 'ai', 'channel' => $channel, 'messages' => [['role' => 'user', 'content' => 1]]],
            ['type' => 'ai', 'channel' => $channel, 'messages' => [['role' => 'user', 'content' => 'ok', 'extra' => true]]],
            ['type' => 'ai', 'channel' => $channel, 'messages' => [['role' => 'user', 'content' => str_repeat('x', 24 * 1024 + 1)]]],
            ['type' => 'ai', 'channel' => $channel, 'messages' => [$valid], 'extra' => true],
        ];
        foreach ($invalid as $payload) {
            $this->postJson($this->aiUrl($server, $database), $payload)
                ->assertStatus(422)
                ->assertExactJson(['error' => 'AI request not permitted.']);
        }
        Http::assertNothingSent();
    }

    public function test_ai_broker_failures_are_generic_and_never_return_partial_content(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        config([
            'database-viewer.ai_token' => 'SERVER-ONLY-AI-TOKEN',
        ]);
        Http::fake([
            'https://studio.greyharbour.net/internal/ai' => Http::response(['error' => 'NEVER-EXPOSE-PROMPT'], 503),
        ]);

        $this->postJson($this->aiUrl($server, $database), [
            'type' => 'ai', 'channel' => $channel,
            'messages' => [['role' => 'user', 'content' => 'PRIVATE-PROMPT']],
        ])->assertStatus(503)->assertExactJson(['error' => 'AI request failed.'])
            ->assertDontSee('NEVER-EXPOSE-PROMPT')->assertDontSee('PRIVATE-PROMPT');
    }

    public function test_ai_broker_does_not_follow_redirects(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        config(['database-viewer.ai_token' => 'SERVER-ONLY-AI-TOKEN']);
        Http::fakeSequence()
            ->push('', 302, ['Location' => 'http://127.0.0.1/private'])
            ->push(['response' => 'redirect target must not be reached']);

        $this->postJson($this->aiUrl($server, $database), [
            'type' => 'ai', 'channel' => $channel,
            'messages' => [['role' => 'user', 'content' => 'test']],
        ])->assertStatus(503)->assertExactJson(['error' => 'AI request failed.']);

        Http::assertSentCount(1);
    }

    public function test_current_database_and_exact_schema_transaction_are_executed(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        $queryResult = $this->emptyResult(0.5);
        $transactionResults = array_map(fn (int $index) => $this->emptyResult($index), range(1, 6));
        $executor = $this->mock(QueryExecutor::class);
        $executor->shouldReceive('execute')->once()->withArgs(fn ($model, $operation) => $model->id === $database->id && $operation === AllowedQuery::CurrentDatabase)->andReturn($queryResult);
        $executor->shouldReceive('executeBatch')->once()->withArgs(fn ($model, $operations) => $model->id === $database->id && $operations === [AllowedQuery::Schema, AllowedQuery::Tables, AllowedQuery::Columns, AllowedQuery::Constraints, AllowedQuery::ConstraintColumns, AllowedQuery::Triggers])->andReturn($transactionResults);

        $this->postJson($this->url($server, $database, true), $this->queryPayload($channel, 'SELECT DATABASE() AS db'))
            ->assertOk()->assertExactJson(['data' => $queryResult]);
        $this->postJson($this->url($server, $database, true), [
            'type' => 'transaction',
            'channel' => $channel,
            'statements' => $this->schemaStatements($database->database),
        ])->assertOk()->assertExactJson(['data' => $transactionResults]);
    }

    public function test_ambiguous_and_invalid_envelopes_execute_nothing(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        $metadata = $this->schemaStatements($database->database)[0];
        $payloads = [
            ['channel' => $channel, 'statement' => 'SELECT 1'],
            ['type' => 'query', 'channel' => $channel, 'statement' => 'SELECT 1', 'statements' => []],
            ['type' => 'query', 'channel' => $channel, 'statement' => 'SELECT 1', 'extra' => true],
            ['type' => 'unknown', 'channel' => $channel, 'statement' => 'SELECT 1'],
            ['type' => 'query', 'channel' => $channel, 'statement' => 1],
            ['type' => 'transaction', 'channel' => $channel, 'statements' => 'not-an-array'],
            $this->queryPayload($channel, $metadata),
            ['type' => 'transaction', 'channel' => $channel, 'statements' => array_slice($this->schemaStatements($database->database), 0, 5)],
        ];
        $executor = $this->mock(QueryExecutor::class);
        $executor->shouldNotReceive('execute');
        $executor->shouldNotReceive('executeBatch');

        foreach ($payloads as $payload) {
            $this->postJson($this->url($server, $database, true), $payload)
                ->assertStatus(422)
                ->assertExactJson(['error' => 'Query not permitted.']);
        }
    }

    public function test_raw_request_body_limit_is_exact_before_execution(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        $json = json_encode($this->queryPayload($channel), JSON_THROW_ON_ERROR);
        $exact = $json.str_repeat(' ', BrokerLimits::MAX_REQUEST_BYTES - strlen($json));
        $over = $exact.' ';
        $this->assertSame(BrokerLimits::MAX_REQUEST_BYTES, strlen($exact));
        $executor = $this->mock(QueryExecutor::class);
        $executor->shouldReceive('execute')->once()->withArgs(fn ($model, $operation) => $model->id === $database->id && $operation === AllowedQuery::Diagnostic)->andReturn($this->emptyResult());
        $executor->shouldNotReceive('executeBatch');
        $serverVariables = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        $this->call('POST', $this->url($server, $database, true), [], [], [], $serverVariables, $exact)->assertOk();
        $this->call('POST', $this->url($server, $database, true), [], [], [], $serverVariables, $over)
            ->assertStatus(413)
            ->assertExactJson(['error' => 'Request is too large.']);
    }

    public function test_serialized_success_response_limit_is_exact_and_never_partial(): void
    {
        [$owner, $server, $database] = $this->fixture();
        $channel = $this->open($owner, $server, $database);
        $exactResult = $this->emptyResult();
        $exactResult['headers'] = [['name' => 'value', 'displayName' => 'value', 'originalType' => 'VAR_STRING', 'type' => 1]];
        $exactResult['rows'] = [['value' => '']];
        $emptyLength = strlen(json_encode(['data' => $exactResult], JSON_THROW_ON_ERROR));
        $exactResult['rows'][0]['value'] = str_repeat('x', BrokerLimits::MAX_RESPONSE_BYTES - $emptyLength);
        $overResult = $exactResult;
        $overResult['rows'][0]['value'] .= 'x';
        $this->assertSame(BrokerLimits::MAX_RESPONSE_BYTES, strlen(json_encode(['data' => $exactResult], JSON_THROW_ON_ERROR)));
        $executor = $this->mock(QueryExecutor::class);
        $executor->shouldReceive('execute')->twice()->andReturn($exactResult, $overResult);

        $response = $this->postJson($this->url($server, $database, true), $this->queryPayload($channel));
        $response->assertOk();
        $this->assertSame(BrokerLimits::MAX_RESPONSE_BYTES, strlen($response->getContent()));
        $this->postJson($this->url($server, $database, true), $this->queryPayload($channel))
            ->assertStatus(503)
            ->assertExactJson(['error' => 'Database query failed.'])
            ->assertDontSee(str_repeat('x', 100), false);
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
        $payload = $this->queryPayload($channel);
        $this->postJson($this->url($server, $database, true), $payload)->assertStatus(419);
        $this->mock(QueryExecutor::class)->shouldReceive('execute')->once()->andReturn(['headers' => [], 'rows' => [], 'stat' => ['rowsAffected' => 0, 'rowsRead' => 0, 'rowsWritten' => null, 'queryDurationMs' => 1.0]]);
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
        $contexts = new ViewerContext;
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
        $statement->expects($this->once())->method('execute')->with([])->willReturn(true);
        $statement->expects($this->once())->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn([['1' => '1']]);
        $statement->method('columnCount')->willReturn(1);
        $statement->method('getColumnMeta')->willReturn(['name' => '1', 'native_type' => 'LONG']);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())->method('prepare')->with('SELECT 1')->willReturn($statement);
        $connector = $this->createMock(MySqlConnector::class);
        $connector->expects($this->once())->method('connect')->with($this->callback(function ($config) use ($database) {
            return $config['host'] === $database->host->host && $config['port'] === $database->host->port
                && $config['database'] === $database->database && $config['username'] === $database->username
                && $config['password'] === 'NEVER-EXPOSE-THIS'
                && $config['options'][\PDO::ATTR_TIMEOUT] === 3
                && $config['options'][\PDO::MYSQL_ATTR_MULTI_STATEMENTS] === false
                && $config['options'][\PDO::MYSQL_ATTR_INIT_COMMAND] === 'SET SESSION max_statement_time=3';
        }))->willReturn($pdo);
        $result = (new MariaDbExecutor($connector, new DatabaseResultSerializer))->execute($database, AllowedQuery::Diagnostic);
        $this->assertSame([['1' => '1']], $result['rows']);
        $this->assertGreaterThanOrEqual(0, $result['stat']['queryDurationMs']);
        $this->assertNotSame('NEVER-EXPOSE-THIS', $database->getRawOriginal('password'));
    }

    public function test_executor_rejects_malformed_result(): void
    {
        [, , $database] = $this->fixture();
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn([['1' => []]]);
        $statement->method('columnCount')->willReturn(1);
        $statement->method('getColumnMeta')->willReturn(false);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $connector = $this->createStub(MySqlConnector::class);
        $connector->method('connect')->willReturn($pdo);
        $this->expectException(\RuntimeException::class);
        (new MariaDbExecutor($connector, new DatabaseResultSerializer))->execute($database, AllowedQuery::Diagnostic);
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
