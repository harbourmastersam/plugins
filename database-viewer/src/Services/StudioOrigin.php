<?php

namespace GreyHarbour\DatabaseViewer\Services;

use InvalidArgumentException;

class StudioOrigin
{
    public static function validate(mixed $value): string
    {
        if (!is_string($value) || !preg_match('~\Ahttps://(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[0-9a-f:]+\])(?::[1-9][0-9]{0,4})?\z~D', $value)
            || !filter_var($value, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Studio must be one exact HTTPS origin.');
        }
        $parts = parse_url($value);
        if (($parts['port'] ?? 443) > 65535) {
            throw new InvalidArgumentException('Invalid Studio origin port.');
        }

        return 'https://'.$parts['host'].(isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '');
    }
}
