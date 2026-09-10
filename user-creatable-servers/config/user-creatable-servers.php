<?php

return [
    'database_limit' => (int) env('UCS_DEFAULT_DATABASE_LIMIT', 0),
    'allocation_limit' => (int) env('UCS_DEFAULT_ALLOCATION_LIMIT', 0),
    'backup_limit' => (int) env('UCS_DEFAULT_BACKUP_LIMIT', 0),

    'can_users_update_servers' => (bool) env('UCS_CAN_USERS_UPDATE_SERVERS', true),
    'can_users_delete_servers' => (bool) env('UCS_CAN_USERS_DELETE_SERVERS', false),

    'deployment_tags' => env('UCS_DEPLOYMENT_TAGS', 'user_creatable_servers'),
    'deployment_ports' => env('UCS_DEPLOYMENT_PORTS', ''),
    'allowed_eggs' => env('UCS_ALLOWED_EGGS', ''),

    'oauth_sync' => [
        'enabled' => (bool) env('UCS_OAUTH_SYNC_ENABLED', env('UCS_OIDC_SYNC_ENABLED', false)),
        'providers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('UCS_OAUTH_SYNC_PROVIDERS', env('UCS_OIDC_SYNC_PROVIDER', 'authentik'))),
        ), fn (string $provider) => $provider !== '')),
        'claim' => env('UCS_OAUTH_SYNC_CLAIM', env('UCS_OIDC_SYNC_CLAIM', 'pelican_limits')),
    ],
];
