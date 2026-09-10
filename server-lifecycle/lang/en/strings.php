<?php
return [
    'settings' => ['title' => 'Server lifecycle settings', 'saved' => 'Server lifecycle settings saved'],
    'archives' => ['title' => 'Archived Servers'],
    'notifications' => [
        'view' => 'View in Pelican',
        'download' => 'Download archive',
        'archive_title' => 'Server archival warning',
        'archive_body' => ':server is scheduled for archival at :date.',
        'delete_title' => 'Archived server deletion warning',
        'delete_body' => ':server is scheduled for permanent deletion at :date.',
        'final_title' => 'Final archived server delivery',
        'final_body' => 'The final copy of :server is available during its deletion grace period.',
    ],
    'errors' => [
        's3_only' => 'Long-term archives require a Pelican BackupHost using the S3 schema.',
        'databases_block_archive' => 'This server has attached Pelican databases. Version 1 cannot safely archive their contents, so archival is refused.',
        'backups_block_archive' => 'This server has existing Pelican backups. Archive, export, or remove those backups before using lifecycle archival.',
        'unsupported_metadata' => 'Archival is refused because :relation cannot yet be safely restored.',
        'conflicting_state' => 'The server is suspended, transferring, installing, restoring, or otherwise busy.',
        'must_be_offline' => 'The server must be confirmed offline. A node communication failure never permits archival.',
    ],
];
