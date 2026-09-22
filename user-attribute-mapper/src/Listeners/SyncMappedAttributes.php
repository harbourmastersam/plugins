<?php

namespace Boy132\UserAttributeMapper\Listeners;

use App\Models\User;
use Boy132\UserAttributeMapper\OAuth\OAuthClaimContext;
use Boy132\UserAttributeMapper\Services\AttributeMappingService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncMappedAttributes
{
    public function __construct(private readonly OAuthClaimContext $context, private readonly AttributeMappingService $mappings) {}

    public function handle(Login $event): void
    {
        if (!$event->user instanceof User || !$this->context->observed()) return;
        if (!$this->context->inspectable() || $this->context->provider() === null) {
            Log::warning('OAuth claims were not inspectable; identity mappings were preserved.', ['user_id' => $event->user->id, 'provider' => $this->context->provider(), 'result' => 'unavailable']);
            return;
        }
        try {
            $this->mappings->apply($event->user, $this->context->provider(), $this->context->claims());
        } catch (Throwable $exception) {
            Log::error('Identity attribute mapping could not be completed; login will continue.', ['user_id' => $event->user->id, 'provider' => $this->context->provider(), 'exception' => $exception::class]);
        }
    }
}
