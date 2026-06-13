<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallRecording;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RecordingImportService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly S3Client $s3Client,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly string $recordingsBucket,
        private readonly string $recordingsEnvironment,
        private readonly bool $transcriptionEnabled = false,
    ) {
    }

    public function import(CallRecording $recording): void
    {
        if ('imported' === $recording->getStatus() && null !== $recording->getS3Key()) {
            return;
        }

        $recording->setStatus('importing')->setImportError(null)->touch();
        $this->entityManager->flush();

        $stream = null;
        try {
            $url = $recording->getProviderDownloadUrl();
            if (null === $url || 'https' !== strtolower((string) parse_url($url, PHP_URL_SCHEME))) {
                throw new \RuntimeException('Recording provider download URL must use HTTPS.');
            }
            if ('' === trim($this->recordingsBucket)) {
                throw new \RuntimeException('AWS_RECORDINGS_BUCKET is missing.');
            }

            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Accept' => 'audio/*, application/octet-stream'],
                'timeout' => 60,
            ]);
            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf(
                    'Telnyx recording download failed with HTTP %d.',
                    $statusCode,
                ));
            }

            $stream = fopen('php://temp/maxmemory:8388608', 'w+b');
            if (false === $stream) {
                throw new \RuntimeException('Unable to create temporary recording stream.');
            }
            $source = $response->toStream(false);
            if (false === stream_copy_to_stream($source, $stream)) {
                throw new \RuntimeException('Unable to stream Telnyx recording response.');
            }
            rewind($stream);

            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? $recording->getContentType() ?? $this->contentType($recording);
            $size = fstat($stream)['size'] ?? null;
            $key = $this->s3Key($recording);

            $this->s3Client->putObject([
                'Bucket' => $this->recordingsBucket,
                'Key' => $key,
                'Body' => $stream,
                'ContentType' => $contentType,
            ]);

            $recording
                ->setS3Bucket($this->recordingsBucket)
                ->setS3Key($key)
                ->setContentType($contentType)
                ->setSizeBytes(is_int($size) ? $size : $recording->getSizeBytes())
                ->setImportedAt(new \DateTimeImmutable())
                ->setImportError(null)
                ->setStatus('imported')
                ->touch();
            $this->entityManager->flush();

            if ($this->transcriptionEnabled) {
                try {
                    $this->messageBus->dispatch(new \App\Message\TranscribeRecordingMessage($recording->getId()));
                } catch (\Throwable $exception) {
                    $this->logger->error('Recording import succeeded but transcription dispatch failed.', [
                        'recording_id' => $recording->getId(),
                        'exception' => $exception,
                    ]);
                }
            }
        } catch (\Throwable $exception) {
            $recording
                ->setStatus('failed')
                ->setImportError($exception->getMessage())
                ->touch();
            $this->entityManager->flush();
            $this->logger->error('Recording import to S3 failed.', [
                'recording_id' => $recording->getId(),
                'provider_recording_id' => $recording->getProviderRecordingId(),
                'exception' => $exception,
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function s3Key(CallRecording $recording): string
    {
        $date = $recording->getRecordingStartedAt() ?? $recording->getCreatedAt();
        $format = strtolower($recording->getFormat() ?? 'wav');
        if (!in_array($format, ['wav', 'mp3'], true)) {
            $format = 'wav';
        }

        // TODO: Replace "default" with the owning tenant ID when tenancy is implemented.
        return sprintf(
            'env/%s/tenant/default/calls/%s/%s/%s/%s/recordings/%s/audio.%s',
            $this->pathPart($this->recordingsEnvironment),
            $date->format('Y'),
            $date->format('m'),
            $date->format('d'),
            $this->pathPart($recording->getCallSession()->getProviderSessionId()),
            $this->pathPart($recording->getProviderRecordingId() ?? (string) $recording->getId()),
            $format,
        );
    }

    private function contentType(CallRecording $recording): string
    {
        return 'mp3' === strtolower($recording->getFormat() ?? '') ? 'audio/mpeg' : 'audio/wav';
    }

    private function pathPart(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? '';

        return '' !== trim($value, '-') ? trim($value, '-') : 'unknown';
    }
}
