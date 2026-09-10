<?php

use App\Models\Node;
use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\AuthoritativeServerState;
use HarbourmasterSam\ServerLifecycle\Services\Status\FreshWingsServerStatusService;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

function statusServer(): Server
{
    $node = new Node();
    $node->forceFill(['fqdn' => 'wings.test', 'scheme' => 'https', 'daemon_listen' => 8080, 'daemon_token' => 'test-token']);
    $server = new Server();
    $server->forceFill(['uuid' => '00000000-0000-0000-0000-000000000001']);
    $server->setRelation('node', $node);

    return $server;
}

it('classifies fresh Wings responses without treating errors as missing', function (int $status, array $body, ?AuthoritativeServerState $expected): void {
    Http::fake(['*' => Http::response($body, $status)]);
    $call = fn () => (new FreshWingsServerStatusService())->get(statusServer());

    $expected === null
        ? expect($call)->toThrow(RuntimeException::class)
        : expect($call())->toBe($expected);
})->with([
    'offline' => [200, ['state' => 'offline'], AuthoritativeServerState::Offline],
    'running' => [200, ['state' => 'running'], AuthoritativeServerState::Active],
    'starting' => [200, ['state' => 'starting'], AuthoritativeServerState::Active],
    'dead unsafe' => [200, ['state' => 'dead'], AuthoritativeServerState::InactiveUnsafe],
    'exited unsafe' => [200, ['state' => 'exited'], AuthoritativeServerState::InactiveUnsafe],
    'confirmed 404' => [404, [], AuthoritativeServerState::ConfirmedMissing],
    'unauthorized' => [401, [], null],
    'forbidden' => [403, [], null],
    'server error' => [500, [], null],
    'bad gateway' => [502, [], null],
    'missing state' => [200, [], null],
    'unknown state' => [200, ['state' => 'mystery'], null],
]);

it('performs a new Wings request for every status read', function (): void {
    Http::fakeSequence()->push(['state' => 'offline'])->push(['state' => 'running']);
    $service = new FreshWingsServerStatusService();
    $server = statusServer();

    expect($service->get($server))->toBe(AuthoritativeServerState::Offline)
        ->and($service->get($server))->toBe(AuthoritativeServerState::Active);
    Http::assertSentCount(2);
});

it('never converts a transport failure into confirmed missing', function (): void {
    Http::fake(['*' => fn () => throw new ConnectionException('connection refused')]);

    expect(fn () => (new FreshWingsServerStatusService())->get(statusServer()))
        ->toThrow(RuntimeException::class, 'could not be contacted');
});
