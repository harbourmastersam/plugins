<?php
return [
    'settings' => ['title' => 'Server lifecycle settings', 'saved' => 'Server lifecycle settings saved'],
    'archives' => ['title' => 'Archived Servers'],
    'errors' => [
        's3_only' => 'Long-term archives require a Pelican BackupHost using the S3 schema.',
        'databases_block_archive' => 'This server has attached Pelican databases. Version 1 cannot safely archive their contents, so archival is refused.',
        'unsupported_metadata' => 'Archival is refused because :relation cannot yet be safely restored.',
        'conflicting_state' => 'The server is suspended, transferring, installing, restoring, or otherwise busy.',
        'must_be_offline' => 'The server must be confirmed offline. A node communication failure never permits archival.',
    ],
];
