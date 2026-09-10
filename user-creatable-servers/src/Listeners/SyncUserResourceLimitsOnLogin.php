<?php

namespace Boy132\UserCreatableServers\Listeners;

use App\Models\User;
use Boy132\UserCreatableServers\Models\UserResourceLimits;
use Boy132\UserCreatableServers\OAuth\OAuthClaimContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SyncUserResourceLimitsOnLogin
{
    public function handle(Login $event): void
    {
        if (!config('user-creatable-servers.oauth_sync.enabled')) {
            return;
        }

        if (!$event->user instanceof User) {
            return;
        }

        $context = app(OAuthClaimContext::class);

        if (!$context->isOAuthLogin() || !$this->providerSelected($context->provider())) {
            return;
        }

        if (!$context->rawAttributesInspectable()) {
            Log::warning('Unable to inspect OAuth user attributes for resource limits.', [
                'user_id' => $event->user->id,
                'provider' => $context->provider(),
            ]);

            return;
        }

        $limits = $context->limits();

        if (!$context->claimPresent()) {
            UserResourceLimits::where('user_id', $event->user->id)->delete();

            return;
        }

        if (!is_array($limits)) {
            Log::warning('Invalid OAuth resource limits received.', [
                'user_id' => $event->user->id,
                'provider' => $context->provider(),
                'errors' => ['claim' => ['The resource limits claim must be an array.']],
            ]);

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
            Log::warning('Invalid OAuth resource limits received.', [
                'user_id' => $event->user->id,
                'provider' => $context->provider(),
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

    private function providerSelected(?string $providerId): bool
    {
        if ($providerId === null) {
            return false;
        }

        $providers = config('user-creatable-servers.oauth_sync.providers', []);
        $providers = is_array($providers) ? $providers : explode(',', (string) $providers);
        $providers = array_map('trim', $providers);

        return in_array('*', $providers, true) || in_array($providerId, $providers, true);
    }
}
