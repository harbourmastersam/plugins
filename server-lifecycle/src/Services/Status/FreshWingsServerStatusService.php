<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Status;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use HarbourmasterSam\ServerLifecycle\Enums\AuthoritativeServerState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RuntimeException;

class FreshWingsServerStatusService
{
    public function __construct(private DaemonServerRepository $repository) {}

    public function get(Server $server): AuthoritativeServerState
    {
        try {
            // getHttpClient() adds Pelican's node-token/User-Agent validation to
            // the normal authenticated daemon client. Do not replace this with
            // getDetails(), which deliberately collapses failures into Missing.
            $response = $this->repository
                ->setServer($server)
                ->getHttpClient()
                ->get("/api/servers/{$server->uuid}");
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Wings could not be contacted for an authoritative status check.', previous: $exception);
        } catch (RequestException $exception) {
            if ($exception->response
                && $exception->response->status() === 404
                && $this->isExpectedWingsResponse($server, $exception->response)) {
                return AuthoritativeServerState::ConfirmedMissing;
            }

            throw new RuntimeException('Wings authoritative status request failed.', previous: $exception);
        }

        if ($response->status() === 404) {
            if ($this->isExpectedWingsResponse($server, $response)) {
                return AuthoritativeServerState::ConfirmedMissing;
            }

            throw new RuntimeException('A 404 response did not contain the expected Wings node identity.');
        }
        if (! $response->successful()) {
            throw new RuntimeException("Wings authoritative status request failed with HTTP {$response->status()}.");
        }
        if (! $this->isExpectedWingsResponse($server, $response)) {
            throw new RuntimeException('The authoritative response did not contain the expected Wings node identity.');
        }

        $state = $response->json('state');
        if (! is_string($state)) {
            throw new RuntimeException('Wings authoritative status response did not contain a valid state.');
        }

        return match (strtolower($state)) {
            'offline' => AuthoritativeServerState::Offline,
            'starting', 'running', 'restarting', 'paused', 'stopping' => AuthoritativeServerState::Active,
            'created', 'exited', 'dead', 'removing' => AuthoritativeServerState::InactiveUnsafe,
            default => throw new RuntimeException('Wings returned an unknown server state.'),
        };
    }

    private function isExpectedWingsResponse(Server $server, Response $response): bool
    {
        $userAgent = $response->header('User-Agent');
        $expectedTokenId = (string) $server->node->daemon_token_id;
        if (! is_string($userAgent) || $expectedTokenId === '') {
            return false;
        }

        return preg_match('/\APelican Wings\/v[^\s()]+ \(id:'.preg_quote($expectedTokenId, '/').'\)\z/', $userAgent) === 1;
    }
}
