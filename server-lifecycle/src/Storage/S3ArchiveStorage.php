<?php

namespace HarbourmasterSam\ServerLifecycle\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Carbon\CarbonInterval;
use HarbourmasterSam\ServerLifecycle\Models\ServerArchive;
use RuntimeException;

class S3ArchiveStorage implements ArchiveStorageInterface
{
    private function clientAndBucket(ServerArchive $archive): array
    {
        $host = $archive->backupHost()->firstOrFail();
        if ($host->getAttribute('type') !== 's3') {
            throw new RuntimeException('Archive storage host is not an S3 host.');
        }
        $configuration = (array) $host->getAttribute('configuration');
        $options = [
            'version' => 'latest',
            'region' => $configuration['region'],
            'credentials' => array_filter([
                'key' => $configuration['key'],
                'secret' => $configuration['secret'],
                'token' => $configuration['token'] ?? null,
            ]),
            'use_path_style_endpoint' => (bool) ($configuration['use_path_style_endpoint'] ?? false),
        ];
        if (! empty($configuration['endpoint'])) {
            $options['endpoint'] = $configuration['endpoint'];
        }

        return [new S3Client($options), $configuration['bucket']];
    }

    public function exists(ServerArchive $archive): bool
    {
        [$client, $bucket] = $this->clientAndBucket($archive);
        try { $client->headObject(['Bucket' => $bucket, 'Key' => $archive->object_key]); return true; }
        catch (AwsException $exception) { if ($exception->getStatusCode() === 404) return false; throw $exception; }
    }

    public function head(ServerArchive $archive): ArchiveObjectMetadata
    {
        [$client, $bucket] = $this->clientAndBucket($archive);
        $result = $client->headObject(['Bucket' => $bucket, 'Key' => $archive->object_key]);
        return new ArchiveObjectMetadata((int) $result['ContentLength'], isset($result['ETag']) ? trim((string) $result['ETag'], '"') : null);
    }

    public function temporaryDownloadUrl(ServerArchive $archive, CarbonInterval $ttl): string
    {
        [$client, $bucket] = $this->clientAndBucket($archive);
        $command = $client->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $archive->object_key, 'ResponseContentDisposition' => 'attachment; filename="'.str($archive->server_name)->slug().'.tar.gz"']);
        return (string) $client->createPresignedRequest($command, '+'.$ttl->totalSeconds.' seconds')->getUri();
    }

    public function downloadToPath(ServerArchive $archive, string $path): void
    {
        [$client, $bucket] = $this->clientAndBucket($archive);
        $client->getObject(['Bucket' => $bucket, 'Key' => $archive->object_key, 'SaveAs' => $path]);
    }

    public function delete(ServerArchive $archive): void
    {
        [$client, $bucket] = $this->clientAndBucket($archive);
        $client->deleteObject(['Bucket' => $bucket, 'Key' => $archive->object_key]);
    }
}
