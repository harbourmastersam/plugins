<?php

namespace HarbourmasterSam\UserAttributeMapper\Enums;

enum MappingLoggingMode: string
{
    case Errors = 'errors';
    case Normal = 'normal';
    case Verbose = 'verbose';

    public static function resolve(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Normal) : self::Normal;
    }

    public function logsSummary(): bool
    {
        return $this !== self::Errors;
    }

    public function logsIndividualResults(): bool
    {
        return $this === self::Verbose;
    }
}
