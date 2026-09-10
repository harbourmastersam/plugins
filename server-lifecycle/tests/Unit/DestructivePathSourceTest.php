<?php

it('adoption never invokes remote backup deletion', function (): void {
    $source = file_get_contents(__DIR__.'/../../src/Services/Archive/AdoptBackupAsArchiveService.php');
    expect(substr_count($source, 'DeleteBackupService'))->toBe(1)->and($source)->not->toContain('deleteObject(');
});

it('deletion happens only after storage verification and adoption', function (): void {
    $source = file_get_contents(__DIR__.'/../../src/Services/Archive/CompleteArchiveService.php');
    expect(strpos($source, '$this->storage->head'))->toBeLessThan(strpos($source, '$this->adopter->handle'))
        ->and(strpos($source, '$this->adopter->handle'))->toBeLessThan(strpos($source, '$this->deletion->handle'));
});

it('permanent deletion addresses an archive rather than a prefix', function (): void {
    $source = file_get_contents(__DIR__.'/../../src/Storage/S3ArchiveStorage.php');
    expect($source)->toContain("'Key' => \$archive->object_key")->not->toContain('deleteObjects');
});
