<x-filament-panels::page>
    <dl class="grid gap-4 md:grid-cols-2">
        <div><dt class="font-medium">State</dt><dd>{{ $lifecycleState->status->value }}</dd></div>
        <div><dt class="font-medium">Automatic lifecycle</dt><dd>{{ $lifecycleState->automatic_enabled ? 'Enabled' : 'Disabled' }}</dd></div>
        <div><dt class="font-medium">Last activity</dt><dd>{{ $lifecycleState->last_activity_at }}</dd></div>
        <div><dt class="font-medium">Last event</dt><dd>{{ $lifecycleState->last_activity_event ?? 'Unknown' }}</dd></div>
        <div><dt class="font-medium">Archive due</dt><dd>{{ $lifecycleState->archive_due_at ?? 'Never' }}</dd></div>
        <div><dt class="font-medium">Exempt until</dt><dd>{{ $lifecycleState->exempt_until ?? 'Not exempt' }}</dd></div>
    </dl>
</x-filament-panels::page>
