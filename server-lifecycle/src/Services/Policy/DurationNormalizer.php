<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Policy;

final class DurationNormalizer
{
    private const FACTORS = ['minutes' => 1, 'hours' => 60, 'days' => 1440, 'weeks' => 10080];

    public function toMinutes(int|string|null $value, string $unit): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value * (self::FACTORS[$unit] ?? 1);
    }

    /** @return array{value: int|null, unit: string} */
    public function fromMinutes(int|string|null $minutes): array
    {
        if ($minutes === null || $minutes === '') {
            return ['value' => null, 'unit' => 'days'];
        }
        $minutes = (int) $minutes;
        foreach (array_reverse(self::FACTORS, true) as $unit => $factor) {
            if ($minutes % $factor === 0) {
                return ['value' => intdiv($minutes, $factor), 'unit' => $unit];
            }
        }

        return ['value' => $minutes, 'unit' => 'minutes'];
    }
}
