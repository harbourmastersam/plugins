<?php

use App\Enums\ContainerStatus;
use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\RetryDeletionDecision;
use HarbourmasterSam\ServerLifecycle\Services\Archive\RetryDeletionEligibilityService;
use Mockery as M;

it('allows confirmed offline and missing only for post-adoption cleanup', function (ContainerStatus $status): void {
    $server = M::mock(Server::class);
    $server->shouldReceive('retrieveStatus')->once()->andReturn($status);

    expect((new RetryDeletionEligibilityService())->decide($server))->toBe(RetryDeletionDecision::ContinueCleanup);
})->with([ContainerStatus::Offline, ContainerStatus::Missing]);

it('revokes deletion authority for an active Wings state', function (): void {
    $server = M::mock(Server::class);
    $server->shouldReceive('retrieveStatus')->once()->andReturn(ContainerStatus::Running);

    expect((new RetryDeletionEligibilityService())->decide($server))->toBe(RetryDeletionDecision::RevokeAuthority);
});

it('does not translate a Wings transport failure into a safe status', function (): void {
    $server = M::mock(Server::class);
    $server->shouldReceive('retrieveStatus')->once()->andThrow(new RuntimeException('unreachable'));

    expect(fn () => (new RetryDeletionEligibilityService())->decide($server))->toThrow(RuntimeException::class);
});
