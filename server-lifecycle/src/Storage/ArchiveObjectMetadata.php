<?php

namespace HarbourmasterSam\ServerLifecycle\Storage;

final readonly class ArchiveObjectMetadata
{
    public function __construct(public int $bytes, public ?string $etag = null) {}
}
