<?php

namespace HarbourmasterSam\ServerLifecycle\Services\Activity;

class MeaningfulActivity
{
    private const EXACT = ['server:console.command', 'server:startup.edit', 'server:settings.rename', 'server:settings.reinstall', 'server:allocation.create', 'server:allocation.delete', 'server:database.create', 'server:database.delete', 'server:subuser.create', 'server:subuser.update', 'server:subuser.delete', 'server:schedule.create', 'server:schedule.update', 'server:schedule.delete', 'server:task.create', 'server:task.update', 'server:task.delete'];
    private const PREFIXES = ['server:power.', 'server:file.'];

    public function includes(string $event): bool
    {
        if ($event === 'server:schedule.execute' || str_starts_with($event, 'server:backup.') || str_starts_with($event, 'server:lifecycle.')) return false;
        return in_array($event, self::EXACT, true) || collect(self::PREFIXES)->contains(fn (string $prefix) => str_starts_with($event, $prefix));
    }
}
