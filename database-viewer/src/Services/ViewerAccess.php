<?php

namespace GreyHarbour\DatabaseViewer\Services;

use App\Enums\SubuserPermission;
use App\Models\Database;
use App\Models\Server;
use App\Models\User;

class ViewerAccess
{
    /** @return array{Server, Database} */
    public function resolve(?User $user, string $serverKey, string $databaseId): array
    {
        abort_unless($user, 401);
        $server = Server::query()->where('uuid_short', $serverKey)->firstOrFail();
        abort_unless($user->canAccessTenant($server), 403);
        // Same check as DatabasePolicy::view/viewAny, with an explicit tenant.
        abort_unless($user->can(SubuserPermission::DatabaseRead, $server), 403);
        abort_if($server->isInConflictState(), 403);
        $database = $server->databases()->whereKey($databaseId)->firstOrFail();

        return [$server, $database];
    }
}
