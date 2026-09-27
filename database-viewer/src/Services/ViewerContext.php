<?php

namespace GreyHarbour\DatabaseViewer\Services;

use Illuminate\Contracts\Session\Session;

class ViewerContext
{
    public function create(Session $session, int $user, int $server, int $database): string
    {
        $channel = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $contexts = $session->get('database-viewer.contexts', []);
        $contexts[$channel] = [$user, $server, $database];
        // Bound session growth; intentionally no timed viewer sessions in this MVP.
        $session->put('database-viewer.contexts', array_slice($contexts, -20, null, true));

        return $channel;
    }

    public function matches(Session $session, string $channel, int $user, int $server, int $database): bool
    {
        return ($session->get('database-viewer.contexts', [])[$channel] ?? null) === [$user, $server, $database];
    }
}
