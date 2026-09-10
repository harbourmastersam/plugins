<?php

use HarbourmasterSam\ServerLifecycle\Services\Activity\MeaningfulActivity;

it('counts deliberate human activity', function (string $event): void {
    expect((new MeaningfulActivity())->includes($event))->toBeTrue();
})->with([
    'power' => 'server:power.start',
    'console' => 'server:console.command',
    'file' => 'server:file.write',
    'startup command' => 'server:startup.edit',
    'startup image' => 'server:startup.image',
    'rename' => 'server:settings.rename',
    'description' => 'server:settings.description',
    'primary allocation' => 'server:allocation.primary',
    'allocation notes' => 'server:allocation.notes',
    'database password rotation' => 'server:database.rotate-password',
    'subuser' => 'server:subuser.update',
    'schedule edit' => 'server:schedule.update',
    'task edit' => 'server:task.update',
]);

it('does not count automatic, denied, or lifecycle activity', function (string $event): void {
    expect((new MeaningfulActivity())->includes($event))->toBeFalse();
})->with([
    'schedule execution' => 'server:schedule.execute',
    'backup' => 'server:backup.complete',
    'lifecycle' => 'server:lifecycle.archive',
    'denied sftp' => 'server:sftp.denied',
]);
