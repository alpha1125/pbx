<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallLeg;
use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Repository\CallLegRepository;
use App\Repository\CallRecordingRepository;
use App\Repository\CallSessionRepository;
use App\Service\TelnyxRecordingProjectionService;
use App\Service\RecordingImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TelnyxRecordingProjectionServiceTest extends TestCase
{
    public function testSavedWebhookUpdatesRequestedRecording(): void
    {
        $session = new CallSession('session-1');
        $leg = new CallLeg($session, 'leg-1');
        $recording = (new CallRecording($session))->setCallLeg($leg);

        $sessions = $this->createStub(CallSessionRepository::class);
        $legs = $this->createMock(CallLegRepository::class);
        $legs->expects(self::once())->method('findOneByProviderLegId')->willReturn($leg);
        $recordings = $this->createMock(CallRecordingRepository::class);
        $recordings->expects(self::once())->method('findOneByProviderRecordingId')->willReturn(null);
        $recordings->expects(self::once())->method('findRequested')->willReturn($recording);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $importer = $this->createMock(RecordingImportService::class);
        $importer->expects(self::once())->method('import')->with($recording);

        $service = new TelnyxRecordingProjectionService(
            $sessions,
            $legs,
            $recordings,
            $entityManager,
            new NullLogger(),
            $importer,
        );
        $raw = ['data' => ['event_type' => 'call.recording.saved']];
        $service->project('call.recording.saved', [
            'payload' => [
                'call_leg_id' => 'leg-1',
                'recording_id' => 'recording-1',
                'format' => 'wav',
                'recording_started_at' => '2026-06-13T10:00:00Z',
                'recording_ended_at' => '2026-06-13T10:02:05Z',
                'recording_urls' => ['wav' => 'https://recordings.telnyx.com/recording.wav'],
            ],
        ], $raw);

        self::assertSame('import_pending', $recording->getStatus());
        self::assertSame('recording-1', $recording->getProviderRecordingId());
        self::assertSame('wav', $recording->getFormat());
        self::assertSame(125, $recording->getDurationSeconds());
        self::assertNull($recording->getS3Bucket());
        self::assertNull($recording->getS3Key());
        self::assertSame('https://recordings.telnyx.com/recording.wav', $recording->getProviderDownloadUrl());
        self::assertSame($raw, $recording->getRawPayload());
    }
}
