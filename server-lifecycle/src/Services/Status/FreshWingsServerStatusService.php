<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Status;

use App\Models\Server;
use HarbourmasterSam\ServerLifecycle\Enums\AuthoritativeServerState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FreshWingsServerStatusService
{
    public function get(Server $server): AuthoritativeServerState
    {
        try {
            $response = Http::daemon($server->node)->get("/api/servers/{$server->uuid}");
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Wings could not be contacted for an authoritative status check.', previous: $exception);
        }

        if ($response->status() === 404) {
            return AuthoritativeServerState::ConfirmedMissing;
        }
        if (! $response->successful()) {
            throw new RuntimeException("Wings authoritative status request failed with HTTP {$response->status()}.");
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
}
