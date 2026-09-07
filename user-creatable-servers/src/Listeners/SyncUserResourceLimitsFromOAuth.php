<?php

namespace Boy132\UserCreatableServers\Listeners;

use App\Events\Auth\OAuthAuthenticated;
use Boy132\UserCreatableServers\Models\UserResourceLimits;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SyncUserResourceLimitsFromOAuth
{
    public function handle(OAuthAuthenticated $event): void
    {
        if (!config('user-creatable-servers.oidc_sync.enabled')) {
            return;
        }

        if ($event->provider->getId() !== config('user-creatable-servers.oidc_sync.provider')) {
            return;
        }

        if (!method_exists($event->oauthUser, 'getRaw')) {
            return;
        }

        $raw = $event->oauthUser->getRaw();
        $claimName = config('user-creatable-servers.oidc_sync.claim', 'pelican_limits');
        $limits = is_array($raw) ? ($raw[$claimName] ?? null) : null;

        if (!is_array($limits)) {
            if (is_array($raw) && array_key_exists($claimName, $raw)) {
                Log::warning('Invalid Authentik resource limits received.', [
                    'user_id' => $event->user->id,
                    'errors' => ['claim' => ['The resource limits claim must be an array.']],
                ]);
            }

            UserResourceLimits::where('user_id', $event->user->id)->delete();

            return;
        }

        $validator = Validator::make($limits, [
            'cpu' => ['required', 'integer', 'min:1'],
            'memory' => ['required', 'integer', 'min:1'],
            'disk' => ['required', 'integer', 'min:1'],
            'server_limit' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            Log::warning('Invalid Authentik resource limits received.', [
                'user_id' => $event->user->id,
                'errors' => $validator->errors()->toArray(),
            ]);

            UserResourceLimits::where('user_id', $event->user->id)->delete();

            return;
        }

        $limits = $validator->validated();

        UserResourceLimits::updateOrCreate(
            ['user_id' => $event->user->id],
            [
                'cpu' => $limits['cpu'],
                'memory' => $limits['memory'],
                'disk' => $limits['disk'],
                'server_limit' => $limits['server_limit'],
            ],
        );
    }
}
