<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallRecording;
use Aws\S3\S3Client;

class RecordingStorageService
{
    public function __construct(
        private readonly S3Client $s3Client,
        private readonly int $presignedUrlTtlSeconds,
    ) {
    }

    public function generatePresignedDownloadUrl(CallRecording $recording): string
    {
        $bucket = $recording->getS3Bucket();
        $key = $recording->getS3Key();
        if (null === $bucket || null === $key) {
            throw new \LogicException('Recording is not available in S3.');
        }

        $command = $this->s3Client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $key,
        ]);

        return (string) $this->s3Client->createPresignedRequest(
            $command,
            sprintf('+%d seconds', $this->presignedUrlTtlSeconds),
        )->getUri();
    }

    public function getPresignedUrlTtlSeconds(): int
    {
        return $this->presignedUrlTtlSeconds;
    }

    public function downloadToTemporaryFile(CallRecording $recording): string
    {
        $bucket = $recording->getS3Bucket();
        $key = $recording->getS3Key();
        if (null === $bucket || null === $key) {
            throw new \LogicException('Recording is not available in S3.');
        }

        $path = tempnam(sys_get_temp_dir(), 'pbx-transcript-');
        if (false === $path) {
            throw new \RuntimeException('Unable to create temporary file for transcription.');
        }

        $result = $this->s3Client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $path,
        ]);

        if (!is_file($path)) {
            throw new \RuntimeException('S3 recording download did not produce a local file.');
        }

        $contentType = $result['ContentType'] ?? null;
        if (is_string($contentType) && '' !== $contentType && null === $recording->getContentType()) {
            $recording->setContentType($contentType);
        }

        return $path;
    }

    public function suggestFilename(CallRecording $recording): string
    {
        $extension = $this->extensionForRecording($recording);

        return sprintf(
            '%s.%s',
            $recording->getProviderRecordingId() ?? 'recording-'.$recording->getId(),
            $extension,
        );
    }

    private function extensionForRecording(CallRecording $recording): string
    {
        $format = strtolower($recording->getFormat() ?? '');
        if (in_array($format, ['wav', 'mp3', 'm4a', 'webm', 'mp4', 'mpeg', 'mpga'], true)) {
            return 'mpeg' === $format ? 'mp3' : $format;
        }

        return match ($recording->getContentType()) {
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'mp4',
            'audio/webm' => 'webm',
            default => 'wav',
        };
    }
}
