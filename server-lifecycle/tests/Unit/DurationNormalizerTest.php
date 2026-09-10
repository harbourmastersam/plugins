<?php

use HarbourmasterSam\ServerLifecycle\Services\Policy\DurationNormalizer;

it('normalizes supported duration units to minutes', function (): void {
    $normalizer = new DurationNormalizer();

    expect($normalizer->toMinutes(30, 'minutes'))->toBe(30)
        ->and($normalizer->toMinutes(2, 'hours'))->toBe(120)
        ->and($normalizer->toMinutes(7, 'days'))->toBe(10080)
        ->and($normalizer->toMinutes(2, 'weeks'))->toBe(20160)
        ->and($normalizer->toMinutes(null, 'days'))->toBeNull();
});

it('selects a lossless display unit', function (): void {
    $normalizer = new DurationNormalizer();

    expect($normalizer->fromMinutes(20160))->toBe(['value' => 2, 'unit' => 'weeks'])
        ->and($normalizer->fromMinutes(90))->toBe(['value' => 90, 'unit' => 'minutes']);
});
