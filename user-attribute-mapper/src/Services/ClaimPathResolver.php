<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

use HarbourmasterSam\UserAttributeMapper\Data\ResolvedClaim;

class ClaimPathResolver
{
    /** @param array<string, mixed> $claims */
    public function resolve(array $claims, string $path): ResolvedClaim
    {
        if ($path === '') {
            return new ResolvedClaim(false);
        }
        $current = $claims;
        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } elseif (is_object($current) && property_exists($current, $segment)) {
                $current = $current->{$segment};
            } else {
                return new ResolvedClaim(false);
            }
        }
        return new ResolvedClaim(true, $current);
    }
}
