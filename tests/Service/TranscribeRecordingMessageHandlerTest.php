<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Message\TranscribeRecordingMessage;
use App\Repository\CallRecordingRepository;
use App\Repository\CallTranscriptRepository;
use App\Service\OpenAiTranscriptionService;
use App\Service\RecordingStorageService;
use App\MessageHandler\TranscribeRecordingMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TranscribeRecordingMessageHandlerTest extends TestCase
{
    public function testSkipsDuplicateCurrentTranscript(): void
    {
        $recording = (new CallRecording(new CallSession('session-1'), 'imported'))
            ->setS3Bucket('bucket')
            ->setS3Key('key')
            ->setFormat('wav');

        $recordings = $this->createMock(CallRecordingRepository::class);
        $recordings->expects(self::once())->method('find')->with(5)->willReturn($recording);
        $transcripts = $this->createMock(CallTranscriptRepository::class);
        $transcripts->expects(self::once())
            ->method('hasCurrentForRecording')
            ->with('openai', 'gpt-4o-mini-transcribe', $recording)
            ->willReturn(true);
        $storage = $this->createMock(RecordingStorageService::class);
        $storage->expects(self::never())->method('downloadToTemporaryFile');
        $openAi = $this->createMock(OpenAiTranscriptionService::class);
        $openAi->expects(self::never())->method('transcribeAudioFile');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $handler = new TranscribeRecordingMessageHandler(
            $recordings,
            $transcripts,
            $storage,
            $openAi,
            $entityManager,
            new NullLogger(),
            true,
            'gpt-4o-mini-transcribe',
        );

        $handler(new TranscribeRecordingMessage(5));

        self::assertTrue(true);
    }
}
