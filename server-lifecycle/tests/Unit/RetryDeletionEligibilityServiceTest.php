<?php

use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\AuthoritativeServerState;
use HarbourmasterSam\ServerLifecycle\Enums\RetryDeletionDecision;
use HarbourmasterSam\ServerLifecycle\Services\Archive\RetryDeletionEligibilityService;
use Mockery as M;
use HarbourmasterSam\ServerLifecycle\Services\Status\FreshWingsServerStatusService;

it('allows confirmed offline and missing only for post-adoption cleanup', function (AuthoritativeServerState $status): void {
    $server = M::mock(Server::class);
    $statuses = M::mock(FreshWingsServerStatusService::class);
    $statuses->shouldReceive('get')->once()->with($server)->andReturn($status);

    expect((new RetryDeletionEligibilityService($statuses))->decide($server))->toBe(RetryDeletionDecision::ContinueCleanup);
})->with([AuthoritativeServerState::Offline, AuthoritativeServerState::ConfirmedMissing]);

it('revokes deletion authority for an active Wings state', function (): void {
    $server = M::mock(Server::class);
    $statuses = M::mock(FreshWingsServerStatusService::class);
    $statuses->shouldReceive('get')->once()->andReturn(AuthoritativeServerState::Active);

    expect((new RetryDeletionEligibilityService($statuses))->decide($server))->toBe(RetryDeletionDecision::RevokeAuthority);
});

it('does not translate a Wings transport failure into a safe status', function (): void {
    $server = M::mock(Server::class);
    $statuses = M::mock(FreshWingsServerStatusService::class);
    $statuses->shouldReceive('get')->once()->andThrow(new RuntimeException('unreachable'));

    expect(fn () => (new RetryDeletionEligibilityService($statuses))->decide($server))->toThrow(RuntimeException::class);
});
