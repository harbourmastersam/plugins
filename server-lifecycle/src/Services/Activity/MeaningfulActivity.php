<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Activity;

class MeaningfulActivity
{
    private const EXACT = [
        'server:console.command',
        'server:startup.edit',
        'server:startup.image',
        'server:settings.rename',
        'server:settings.description',
        'server:settings.reinstall',
        'server:allocation.create',
        'server:allocation.delete',
        'server:allocation.primary',
        'server:allocation.notes',
        'server:database.create',
        'server:database.delete',
        'server:database.rotate-password',
        'server:subuser.create',
        'server:subuser.update',
        'server:subuser.delete',
        'server:schedule.create',
        'server:schedule.update',
        'server:schedule.delete',
        'server:task.create',
        'server:task.update',
        'server:task.delete',
    ];
    private const PREFIXES = ['server:power.', 'server:file.'];

    public function includes(string $event): bool
    {
        if ($event === 'server:schedule.execute'
            || str_starts_with($event, 'server:backup.')
            || str_starts_with($event, 'server:lifecycle.')) {
            return false;
        }
        if (in_array($event, self::EXACT, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($event, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
