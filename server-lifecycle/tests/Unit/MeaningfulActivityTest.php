<?php

use HarbourmasterSam\ServerLifecycle\Services\Activity\MeaningfulActivity;

it('counts deliberate human activity', function (): void {
    $filter = new MeaningfulActivity();
    expect($filter->includes('server:power.start'))->toBeTrue()
        ->and($filter->includes('server:console.command'))->toBeTrue()
        ->and($filter->includes('server:file.write'))->toBeTrue();
});

it('does not count automatic or lifecycle activity', function (): void {
    $filter = new MeaningfulActivity();
    expect($filter->includes('server:schedule.execute'))->toBeFalse()
        ->and($filter->includes('server:backup.complete'))->toBeFalse()
        ->and($filter->includes('server:lifecycle.archive'))->toBeFalse();
});
