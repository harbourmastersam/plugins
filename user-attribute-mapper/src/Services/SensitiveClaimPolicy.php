<?php

namespace HarbourmasterSam\UserAttributeMapper\Services;

final class SensitiveClaimPolicy
{
    private const SENSITIVE_SEGMENTS = ['access_token', 'refresh_token', 'id_token', 'client_secret', 'authorization_code', 'code', 'token', 'password', 'secret'];

    public function isSensitivePath(string $path): bool
    {
        foreach (explode('.', $path) as $segment) {
            if (in_array(strtolower($segment), self::SENSITIVE_SEGMENTS, true)) return true;
        }
        return false;
    }

    /** @param array<string, mixed> $claims @return array<string, mixed> */
    public function filter(array $claims): array
    {
        foreach ($claims as $key => $value) {
            if ($this->isSensitivePath((string) $key)) unset($claims[$key]);
            elseif (is_array($value)) $claims[$key] = $this->filter($value);
        }
        return $claims;
    }
}
