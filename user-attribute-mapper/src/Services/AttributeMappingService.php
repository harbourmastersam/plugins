<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use App\Models\User;
use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use HarbourmasterSam\UserAttributeMapper\Enums\MissingClaimBehavior;
use HarbourmasterSam\UserAttributeMapper\Enums\MappingSourceType;
use HarbourmasterSam\UserAttributeMapper\Data\ResolvedClaim;
use HarbourmasterSam\UserAttributeMapper\Models\AttributeMapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class AttributeMappingService
{
    public function __construct(private readonly UserAttributeRegistryContract $registry, private readonly UserAttributeService $attributes, private readonly ClaimPathResolver $claims) {}

    /** @param array<string, mixed> $rawClaims */
    public function apply(User $user, string $provider, array $rawClaims): void
    {
        // Wildcard mappings are retained as legacy records, but are deliberately inactive:
        // claim schemas differ between providers, so only the authenticating provider applies.
        AttributeMapping::query()->where('enabled', true)->where('provider', $provider)->orderBy('priority')->orderBy('id')->each(function (AttributeMapping $mapping) use ($user, $provider, $rawClaims): void {
            $context = ['user_id' => $user->id, 'provider' => $provider, 'mapping_id' => $mapping->id, 'source_type' => $mapping->source_type->value, 'target_attribute' => $mapping->target_attribute];
            if ($mapping->source_type === MappingSourceType::Claim) {
                $context['source_path'] = $mapping->source_value;
            }
            $definition = $this->registry->get($mapping->target_attribute);
            if ($definition === null || !$definition->writableFromIdentity) {
                Log::warning('Identity attribute mapping unavailable.', $context + ['result' => 'unavailable']);
                return;
            }
            $claim = $mapping->source_type === MappingSourceType::Static
                ? new ResolvedClaim(present: true, value: $mapping->source_value)
                : $this->claims->resolve($rawClaims, $mapping->source_value);
            if (!$claim->present) {
                $cleared = $mapping->missing_claim_behavior === MissingClaimBehavior::Clear && $this->attributes->clearFromIdentity($user, $mapping->target_attribute);
                Log::info('Identity attribute mapping completed.', $context + ['result' => $cleared ? 'updated' : 'missing']);
                return;
            }
            try {
                $this->attributes->setFromIdentity($user, $mapping->target_attribute, $claim->value);
                Log::info('Identity attribute mapping completed.', $context + ['result' => 'updated']);
            } catch (Throwable $exception) {
                Log::warning('Identity attribute mapping failed.', $context + ['result' => 'invalid', 'exception' => $exception::class]);
            }
        });
    }
}
