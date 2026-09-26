<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use App\Models\User;
use HarbourmasterSam\UserAttributeMapper\Contracts\UserAttributeRegistryContract;
use HarbourmasterSam\UserAttributeMapper\Enums\MissingClaimBehavior;
use HarbourmasterSam\UserAttributeMapper\Enums\MappingSourceType;
use HarbourmasterSam\UserAttributeMapper\Enums\MappingLoggingMode;
use HarbourmasterSam\UserAttributeMapper\Enums\AttributeMutationResult;
use HarbourmasterSam\UserAttributeMapper\Models\AttributeMapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class AttributeMappingService
{
    private readonly MappingCandidateResolver $candidates;

    public function __construct(private readonly UserAttributeRegistryContract $registry, private readonly UserAttributeService $attributes, ClaimPathResolver $claims, ?AttributeTransformationService $transformations = null)
    {
        $this->candidates = new MappingCandidateResolver($claims, $transformations ?? new AttributeTransformationService());
    }

    /** @param array<string, mixed> $rawClaims */
    public function apply(User $user, string $provider, array $rawClaims): void
    {
        // Wildcard mappings are retained as legacy records, but are deliberately inactive:
        // claim schemas differ between providers, so only the authenticating provider applies.
        $mappings = AttributeMapping::query()->where('enabled', true)->where('provider', $provider)->orderBy('priority')->orderBy('id')->get();
        if ($mappings->isEmpty()) {
            return;
        }

        $mode = $this->loggingMode();
        $chains = $mappings->groupBy('target_attribute');
        $stats = ['targets' => $chains->count(), 'processed' => 0, 'updated' => 0, 'unchanged' => 0, 'cleared' => 0, 'missing' => 0, 'unavailable' => 0, 'invalid' => 0];

        foreach ($chains as $target => $chain) {
            $primary = $chain->first();
            $definition = $this->registry->get($target);
            if ($definition === null || !$definition->writableFromIdentity) {
                $stats['unavailable']++;
                Log::warning('Identity attribute mapping unavailable.', ['user_id' => $user->id, 'provider' => $provider, 'target_attribute' => $target, 'result' => 'unavailable']);
                continue;
            }

            $selected = false;
            foreach ($chain as $mapping) {
                $stats['processed']++;
                $context = ['user_id' => $user->id, 'provider' => $provider, 'mapping_id' => $mapping->id, 'source_type' => $mapping->source_type->value, 'target_attribute' => $target];
                if ($mapping->source_type === MappingSourceType::Claim) $context['source_path'] = $mapping->source_value;
                try {
                    $candidate = $this->candidates->resolve($mapping->source_type, $mapping->source_value, $mapping->transforms ?? [], $rawClaims);
                    if (!$candidate['present']) continue;
                    $selected = true;
                    $mutation = $this->attributes->setFromIdentity($user, $target, $candidate['value']);
                    $result = $mutation?->value ?? 'updated';
                    $stats[$result]++;
                    if ($mode->logsIndividualResults()) Log::info('Identity attribute mapping completed.', $context + ['result' => $result]);
                } catch (Throwable $exception) {
                    $selected = true;
                    $stats['invalid']++;
                    Log::warning('Identity attribute mapping failed.', $context + ['result' => 'invalid', 'exception' => $exception::class]);
                }
                break; // Present candidates succeed or fail authoritatively; neither falls through.
            }

            if (!$selected) {
                $result = 'missing';
                if ($primary->missing_claim_behavior === MissingClaimBehavior::Clear) {
                    $mutation = $this->attributes->clearFromIdentity($user, $target);
                    $result = $mutation === AttributeMutationResult::Cleared ? 'cleared' : 'unchanged';
                }
                $stats[$result]++;
                if ($mode->logsIndividualResults()) Log::info('Identity attribute mapping completed.', [
                    'user_id' => $user->id, 'provider' => $provider, 'target_attribute' => $target, 'result' => $result,
                ]);
            }
        }

        if ($mode->logsSummary()) {
            Log::info('User attribute mapping completed.', ['user_id' => $user->id, 'provider' => $provider] + $stats);
        }
    }

    private function loggingMode(): MappingLoggingMode
    {
        return MappingLoggingMode::resolve(config('user-attribute-mapper.logging_mode', MappingLoggingMode::Normal->value));
    }
}
