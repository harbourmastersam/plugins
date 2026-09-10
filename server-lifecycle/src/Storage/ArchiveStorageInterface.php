<?php

namespace HarbourmasterSam\ServerLifecycle\Storage;

use Carbon\CarbonInterval;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;

interface ArchiveStorageInterface
{
    public function exists(ServerArchive $archive): bool;
    public function head(ServerArchive $archive): ArchiveObjectMetadata;
    public function temporaryDownloadUrl(ServerArchive $archive, CarbonInterval $ttl): string;
    public function downloadToPath(ServerArchive $archive, string $path): void;
    public function delete(ServerArchive $archive): void;
}
