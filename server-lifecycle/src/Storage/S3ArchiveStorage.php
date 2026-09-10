<?php

namespace HarbourmasterSam\ServerLifecycle\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Carbon\CarbonInterval;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use Illuminate\Support\Arr;
use RuntimeException;

class S3ArchiveStorage implements ArchiveStorageInterface
{
    private function clientAndBucket(ServerArchive $archive): array
    {
        $host = $archive->backupHost()->firstOrFail();
        if ($host->schema !== 's3') {
            throw new RuntimeException('Archive storage host is not an S3 host.');
        }
        $configuration = (array) $host->configuration;
        $options = Arr::except($configuration, ['bucket', 'key', 'secret', 'token']);
        $options['version'] = 'latest';
        $options['credentials'] = array_filter(Arr::only($configuration, ['key', 'secret', 'token']));

        return [new S3Client($options), $configuration['bucket']];
    }

    public function exists(ServerArchive $archive): bool
    {
        [$client, $bucket] = $this->clientAndBucket($archive);
        try { $client->headObject(['Bucket' => $bucket, 'Key' => $archive->object_key]); return true; }
        catch (AwsException $exception) {
            if ($exception->getStatusCode() === 404) {
                return false;
            }
            throw new RuntimeException('Unable to check the archive object on S3-compatible storage.');
        }
    }

    public function head(ServerArchive $archive): ArchiveObjectMetadata
    {
        [$client, $bucket] = $this->clientAndBucket($archive);
        try {
            $result = $client->headObject(['Bucket' => $bucket, 'Key' => $archive->object_key]);
        } catch (AwsException) {
            throw new RuntimeException('Unable to read archive metadata from S3-compatible storage.');
        }
        return new ArchiveObjectMetadata((int) $result['ContentLength'], isset($result['ETag']) ? trim((string) $result['ETag'], '"') : null);
    }

    public function temporaryDownloadUrl(ServerArchive $archive, CarbonInterval $ttl): string
    {
        [$client, $bucket] = $this->clientAndBucket($archive);
        try {
            $command = $client->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $archive->object_key, 'ResponseContentDisposition' => 'attachment; filename="'.str($archive->server_name)->slug().'.tar.gz"']);

            return (string) $client->createPresignedRequest($command, '+'.$ttl->totalSeconds.' seconds')->getUri();
        } catch (AwsException) {
            throw new RuntimeException('Unable to create a temporary archive download URL.');
        }
    }

    public function downloadToPath(ServerArchive $archive, string $path): void
    {
        [$client, $bucket] = $this->clientAndBucket($archive);
        try {
            $client->getObject(['Bucket' => $bucket, 'Key' => $archive->object_key, 'SaveAs' => $path]);
        } catch (AwsException) {
            throw new RuntimeException('Unable to download the archive from S3-compatible storage.');
        }
    }

    public function delete(ServerArchive $archive): void
    {
        [$client, $bucket] = $this->clientAndBucket($archive);
        try {
            $client->deleteObject(['Bucket' => $bucket, 'Key' => $archive->object_key]);
        } catch (AwsException) {
            throw new RuntimeException('Unable to delete the archive from S3-compatible storage.');
        }
    }
}
