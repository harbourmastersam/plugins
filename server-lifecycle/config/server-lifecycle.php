<?php

return [
    'enabled' => env('SERVER_LIFECYCLE_ENABLED', false),
    'users_may_archive' => env('SERVER_LIFECYCLE_USERS_MAY_ARCHIVE', false),
    'users_may_restore' => env('SERVER_LIFECYCLE_USERS_MAY_RESTORE', true),
    'users_may_download' => env('SERVER_LIFECYCLE_USERS_MAY_DOWNLOAD', true),
    'users_may_delete' => env('SERVER_LIFECYCLE_USERS_MAY_DELETE', false),
    'attachment_max_bytes' => env('SERVER_LIFECYCLE_ATTACHMENT_MAX_BYTES', 20 * 1024 * 1024),
    'download_ttl_seconds' => 300,
];
