<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Message\TranscribeRecordingMessage;
use App\Service\RecordingImportService;
use Aws\Result;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RecordingImportServiceTest extends TestCase
{
    public function testImportStreamsProviderRecordingIntoS3(): void
    {
        $session = new CallSession('session-1');
        $recording = (new CallRecording($session, 'import_pending'))
            ->setProviderRecordingId('recording-1')
            ->setProviderDownloadUrl('https://recordings.telnyx.com/recording.wav')
            ->setFormat('wav')
            ->setRecordingStartedAt(new \DateTimeImmutable('2026-06-13T10:00:00Z'));
        $id = new \ReflectionProperty($recording, 'id');
        $id->setValue($recording, 42);

        $httpClient = new MockHttpClient(new MockResponse('wave-bytes', [
            'http_code' => 200,
            'response_headers' => ['content-type: audio/wav'],
        ]));
        $s3Client = new class extends S3Client {
            /** @var array<string, mixed>|null */
            public ?array $uploaded = null;

            public function __construct()
            {
            }

            public function __call($name, array $arguments)
            {
                if ('putObject' !== $name) {
                    throw new \BadMethodCallException($name);
                }

                $this->uploaded = $arguments[0];
                $this->uploaded['contents'] = stream_get_contents($arguments[0]['Body']);

                return new Result();
            }
        };
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (TranscribeRecordingMessage $message): bool => 42 === $message->callRecordingId));

        (new RecordingImportService(
            $httpClient,
            $s3Client,
            $entityManager,
            new NullLogger(),
            $bus,
            'recordings-bucket',
            'dev',
            true,
        ))->import($recording);

        self::assertSame('imported', $recording->getStatus());
        self::assertSame('recordings-bucket', $recording->getS3Bucket());
        self::assertSame(
            'env/dev/tenant/default/calls/2026/06/13/session-1/recordings/recording-1/audio.wav',
            $recording->getS3Key(),
        );
        self::assertSame('audio/wav', $recording->getContentType());
        self::assertSame(10, $recording->getSizeBytes());
        self::assertNotNull($recording->getImportedAt());
        self::assertSame('wave-bytes', $s3Client->uploaded['contents']);
        self::assertSame('recordings-bucket', $s3Client->uploaded['Bucket']);
        self::assertSame($recording->getS3Key(), $s3Client->uploaded['Key']);
    }
}
