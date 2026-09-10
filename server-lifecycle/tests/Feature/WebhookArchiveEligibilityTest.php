<?php

use App\Enums\WebhookScope;
use App\Models\BackupHost;
use App\Models\Server;
use App\Models\WebhookConfiguration;
use HarbourmasterSam\ServerLifecycle\Models\LifecyclePolicy;
use HarbourmasterSam\ServerLifecycle\Services\Archive\ArchiveEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function eligibilityPolicy(): LifecyclePolicy
{
    $host = new BackupHost();
    $host->forceFill(['schema' => 's3']);
    $policy = new LifecyclePolicy();
    $policy->setRelation('archiveBackupHost', $host);

    return $policy;
}

it('blocks a server-scoped webhook before archival can begin', function (): void {
    $server = Server::factory()->create();
    WebhookConfiguration::factory()->create([
        'scope' => WebhookScope::Server,
        'server_id' => $server->id,
    ]);

    expect(fn () => app(ArchiveEligibilityService::class)->assertEligible($server, eligibilityPolicy()))
        ->toThrow(RuntimeException::class, 'webhook configurations');
});

it('does not block a server because a global webhook exists', function (): void {
    $server = Server::factory()->create();
    WebhookConfiguration::factory()->create([
        'scope' => WebhookScope::Global,
        'server_id' => null,
    ]);

    expect(WebhookConfiguration::query()
        ->where('scope', 'server')
        ->where('server_id', $server->id)
        ->exists())->toBeFalse();

    app(ArchiveEligibilityService::class)->assertEligible($server, eligibilityPolicy());
});
