<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\TelnyxWebhookController;
use App\Entity\CallLeg;
use App\Entity\CallSession;
use App\Entity\TelnyxEvent;
use App\Repository\TelnyxEventRepository;
use App\Service\PocBrowserSoftphoneTranscriptService;
use App\Service\TelnyxCallControlService;
use App\Service\TelnyxCallProjectionService;
use App\Service\TelnyxCallStateService;
use App\Service\ClientStateService;
use App\Service\CapturePolicyResolver;
use App\Service\ClickToCallService;
use App\Service\BrowserCallEventReconcilerService;
use App\Service\DevTelnyxTranscriptionTestService;
use App\Service\TelnyxCaptureService;
use App\Service\TelnyxRecordingProjectionService;
use App\Service\TelnyxTranscriptionService;
use App\Transcription\SttProviderInterface;
use App\Transcription\SttProviderRegistry;
use App\Transcription\WebhookDrivenSttProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelnyxWebhookControllerTest extends TestCase
{
    public function testForwardingFlowDoesNotRequireDirectionAfterCallInitiated(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'body' => json_decode($options['body'], true),
                ];

                return new MockResponse('{}', ['http_code' => 200]);
            },
        );
        $logger = new NullLogger();
        $controller = new TelnyxWebhookController(
            new TelnyxCallControlService($httpClient, $logger, 'test-api-key'),
            new TelnyxCallStateService(new ArrayAdapter()),
            $this->clickToCall(false),
            new ClientStateService(),
            new CapturePolicyResolver(true, true),
            new TelnyxTranscriptionService(),
            $logger,
            '+14168880123',
            '+12892079888',
        );
        $handleEvent = new \ReflectionMethod($controller, 'handleCallControlEvent');

        $handleEvent->invoke($controller, 'call.initiated', [
            'call_control_id' => 'inbound-control-id',
            'call_leg_id' => 'inbound-leg-id',
            'call_session_id' => 'inbound-session-id',
            'connection_id' => 'connection-id',
            'direction' => 'incoming',
        ], 'event-1');
        $handleEvent->invoke($controller, 'call.answered', [
            'call_control_id' => 'inbound-control-id',
            'call_session_id' => 'inbound-session-id',
        ], 'event-2');
        $handleEvent->invoke($controller, 'call.speak.ended', [
            'call_control_id' => 'inbound-control-id',
            'call_session_id' => 'inbound-session-id',
        ], 'event-3');
        $handleEvent->invoke($controller, 'call.answered', [
            'call_control_id' => 'outbound-control-id',
            'client_state' => base64_encode(json_encode([
                'inbound_call_session_id' => 'inbound-session-id',
            ], JSON_THROW_ON_ERROR)),
        ], 'event-4');
        $handleEvent->invoke($controller, 'call.hangup', [
            'call_control_id' => 'outbound-control-id',
            'client_state' => base64_encode(json_encode([
                'inbound_call_session_id' => 'inbound-session-id',
            ], JSON_THROW_ON_ERROR)),
            'hangup_source' => 'callee',
        ], 'event-5');

        self::assertCount(5, $requests);
        self::assertStringEndsWith('/inbound-control-id/actions/answer', $requests[0]['url']);
        self::assertStringEndsWith('/inbound-control-id/actions/speak', $requests[1]['url']);
        self::assertSame(
            'Thank you for calling FirstFire, this call will be recorded for transcription and quality purposes. Please hold while I connect you.',
            $requests[1]['body']['payload'],
        );
        self::assertStringEndsWith('/calls', $requests[2]['url']);
        self::assertSame('+14168880123', $requests[2]['body']['to']);
        self::assertStringEndsWith('/inbound-control-id/actions/bridge', $requests[3]['url']);
        self::assertSame('outbound-control-id', $requests[3]['body']['call_control_id']);
        self::assertStringEndsWith('/inbound-control-id/actions/hangup', $requests[4]['url']);
    }

    public function testDuplicateEventReturnsSuccessWithoutProjection(): void
    {
        $repository = $this->createMock(TelnyxEventRepository::class);
        $repository->expects(self::once())->method('findOneByProviderEventId')->willReturn(
            new TelnyxEvent('event-duplicate', 'call.initiated', []),
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $projection = $this->createMock(TelnyxCallProjectionService::class);
        $projection->expects(self::never())->method('project');
        $recordingProjection = $this->createMock(TelnyxRecordingProjectionService::class);
        $recordingProjection->expects(self::never())->method('project');
        $capture = $this->createMock(TelnyxCaptureService::class);
        $capture->expects(self::never())->method('startForBridgedInboundLeg');
        $capture->expects(self::never())->method('startForInboundIntroCompleted');
        $devTest = $this->createMock(DevTelnyxTranscriptionTestService::class);
        $devTest->expects(self::never())->method('handleWebhook');
        $reconciler = $this->browserCallReconciler();

        $response = $this->controller(new NullLogger())(
            $this->webhookRequest('event-duplicate', 'call.initiated'),
            $repository,
            $entityManager,
            $projection,
            $reconciler,
            $recordingProjection,
            $capture,
            $devTest,
            $this->providers(),
            new PocBrowserSoftphoneTranscriptService(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['ok' => true, 'duplicate' => true],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testProjectionFailureIsLoggedAndStillReturnsSuccess(): void
    {
        $repository = $this->createMock(TelnyxEventRepository::class);
        $repository->expects(self::once())->method('findOneByProviderEventId')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $projection = $this->createMock(TelnyxCallProjectionService::class);
        $projection->expects(self::once())
            ->method('project')
            ->willThrowException(new \RuntimeException('projection failed'));
        $recordingProjection = $this->createMock(TelnyxRecordingProjectionService::class);
        $recordingProjection->expects(self::once())->method('project');
        $capture = $this->createMock(TelnyxCaptureService::class);
        $capture->expects(self::never())->method('startForBridgedInboundLeg');
        $capture->expects(self::never())->method('startForInboundIntroCompleted');
        $devTest = $this->createMock(DevTelnyxTranscriptionTestService::class);
        $devTest->expects(self::once())->method('handleWebhook')->willReturn(false);
        $reconciler = $this->browserCallReconciler();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Telnyx call-state projection failed after webhook persistence.',
                self::callback(static fn (array $context): bool => (
                    'event-projection-failure' === $context['provider_event_id']
                    && $context['exception'] instanceof \RuntimeException
                )),
            );

        $response = $this->controller($logger)(
            $this->webhookRequest('event-projection-failure', 'call.custom'),
            $repository,
            $entityManager,
            $projection,
            $reconciler,
            $recordingProjection,
            $capture,
            $devTest,
            $this->providers(),
            new PocBrowserSoftphoneTranscriptService(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['ok' => true],
            json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testRecordingProjectionFailureStillReturnsSuccess(): void
    {
        $repository = $this->createMock(TelnyxEventRepository::class);
        $repository->expects(self::once())->method('findOneByProviderEventId')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $projection = $this->createMock(TelnyxCallProjectionService::class);
        $projection->expects(self::once())->method('project');
        $recordingProjection = $this->createMock(TelnyxRecordingProjectionService::class);
        $recordingProjection->expects(self::once())
            ->method('project')
            ->willThrowException(new \RuntimeException('recording projection failed'));
        $capture = $this->createMock(TelnyxCaptureService::class);
        $capture->expects(self::never())->method('startForBridgedInboundLeg');
        $capture->expects(self::never())->method('startForInboundIntroCompleted');
        $devTest = $this->createMock(DevTelnyxTranscriptionTestService::class);
        $devTest->expects(self::once())->method('handleWebhook')->willReturn(false);
        $reconciler = $this->browserCallReconciler();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Telnyx recording projection failed after webhook persistence.',
                self::callback(static fn (array $context): bool => (
                    'event-recording-failure' === $context['provider_event_id']
                    && $context['exception'] instanceof \RuntimeException
                )),
            );

        $response = $this->controller($logger)(
            $this->webhookRequest('event-recording-failure', 'call.recording.saved'),
            $repository,
            $entityManager,
            $projection,
            $reconciler,
            $recordingProjection,
            $capture,
            $devTest,
            $this->providers(),
            new PocBrowserSoftphoneTranscriptService(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRecordingSavedWebhookIsRoutedToTranscriptionProvider(): void
    {
        $repository = $this->createMock(TelnyxEventRepository::class);
        $repository->expects(self::once())->method('findOneByProviderEventId')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $projection = $this->createMock(TelnyxCallProjectionService::class);
        $projection->expects(self::once())->method('project');
        $recordingProjection = $this->createMock(TelnyxRecordingProjectionService::class);
        $recordingProjection->expects(self::once())->method('project');
        $capture = $this->createMock(TelnyxCaptureService::class);
        $capture->expects(self::never())->method('startForBridgedInboundLeg');
        $capture->expects(self::never())->method('startForInboundIntroCompleted');
        $devTest = $this->createMock(DevTelnyxTranscriptionTestService::class);
        $devTest->expects(self::once())->method('handleWebhook')->willReturn(false);
        $reconciler = $this->browserCallReconciler();

        $provider = new class() implements SttProviderInterface, WebhookDrivenSttProviderInterface {
            public bool $handled = false;

            public function getName(): string
            {
                return 'telnyx';
            }

            public function submit(\App\Entity\TranscriptionJob $job): void
            {
            }

            public function handleWebhook(array $payload, TelnyxEvent $event): void
            {
                $this->handled = true;
            }
        };

        $response = $this->controller(new NullLogger())(
            $this->webhookRequest('event-recording-transcription-route', 'call.recording.saved'),
            $repository,
            $entityManager,
            $projection,
            $reconciler,
            $recordingProjection,
            $capture,
            $devTest,
            new SttProviderRegistry([$provider], 'telnyx'),
            new PocBrowserSoftphoneTranscriptService(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($provider->handled);
    }

    public function testBrowserCallTranscriptionIsMirroredToThePocTranscriptStore(): void
    {
        $controller = $this->controller(new NullLogger());
        $reflection = new \ReflectionMethod($controller, 'publishPocTranscriptSegment');

        $session = (new CallSession('provider-session-1'))
            ->setCallMode(CallSession::CALL_MODE_BROWSER);
        $leg = (new CallLeg($session, 'provider-leg-1'))
            ->setDirection('outgoing')
            ->setRole(CallLeg::ROLE_AGENT)
            ->setCallControlId('control-1');
        $event = (new TelnyxEvent('event-transcript-1', 'call.transcription', [
            'data' => [
                'payload' => [
                    'call_control_id' => 'control-1',
                    'transcription_data' => [
                        'transcription_track' => 'outbound',
                        'is_final' => true,
                    ],
                    'transcript' => 'Hello from Telnyx',
                ],
            ],
        ], 'control-1'))
            ->setCallSession($session)
            ->setCallLeg($leg);
        $transcripts = new PocBrowserSoftphoneTranscriptService();
        $transcripts->registerCallControlId('poc-call-1', 'control-1');

        $reflection->invoke($controller, $event, $event->getPayload()['data'], $transcripts);

        $stored = $transcripts->fetchSince('poc-call-1', 0);
        self::assertCount(1, $stored['segments']);
        self::assertSame('Hello from Telnyx', $stored['segments'][0]['text']);
        self::assertSame('customer', $stored['segments'][0]['speaker']);
    }

    public function testBrowserCallTranscriptionIsMirroredWhenWebhookEventSessionIsNotHydrated(): void
    {
        $controller = $this->controller(new NullLogger());
        $reflection = new \ReflectionMethod($controller, 'publishPocTranscriptSegment');

        $session = (new CallSession('provider-session-2'))
            ->setCallMode(CallSession::CALL_MODE_BROWSER);
        $leg = (new CallLeg($session, 'provider-leg-2'))
            ->setDirection('outgoing')
            ->setRole(CallLeg::ROLE_AGENT)
            ->setCallControlId('control-2');
        $event = new TelnyxEvent('event-transcript-2', 'call.transcription', [
            'data' => [
                'payload' => [
                    'call_control_id' => 'control-2',
                    'transcription_data' => [
                        'transcription_track' => 'outbound',
                        'is_final' => true,
                    ],
                    'transcript' => 'Hello from an unhydrated Telnyx event',
                ],
            ],
        ], 'control-2');
        $event->setCallLeg($leg);

        $transcripts = new PocBrowserSoftphoneTranscriptService();
        $transcripts->registerCallControlId('poc-call-2', 'control-2');

        $reflection->invoke($controller, $event, $event->getPayload()['data'], $transcripts);

        $stored = $transcripts->fetchSince('poc-call-2', 0);
        self::assertCount(1, $stored['segments']);
        self::assertSame('Hello from an unhydrated Telnyx event', $stored['segments'][0]['text']);
        self::assertSame('customer', $stored['segments'][0]['speaker']);
    }

    public function testTranscriptionWebhookInvokesPocTranscriptMirroring(): void
    {
        $repository = $this->createMock(TelnyxEventRepository::class);
        $repository->expects(self::once())->method('findOneByProviderEventId')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $projection = $this->createMock(TelnyxCallProjectionService::class);
        $projection->expects(self::once())->method('project');

        $recordingProjection = $this->createMock(TelnyxRecordingProjectionService::class);
        $recordingProjection->expects(self::once())->method('project');

        $capture = $this->createMock(TelnyxCaptureService::class);
        $capture->expects(self::never())->method('startForBridgedInboundLeg');
        $capture->expects(self::never())->method('startForInboundIntroCompleted');

        $devTest = $this->createMock(DevTelnyxTranscriptionTestService::class);
        $devTest->expects(self::once())->method('handleWebhook')->willReturn(false);

        $provider = new class() implements SttProviderInterface, WebhookDrivenSttProviderInterface {
            public bool $handled = false;

            public function getName(): string
            {
                return 'telnyx';
            }

            public function submit(\App\Entity\TranscriptionJob $job): void
            {
            }

            public function handleWebhook(array $payload, TelnyxEvent $event): void
            {
                $this->handled = true;
            }
        };

        $transcripts = new PocBrowserSoftphoneTranscriptService();
        $transcripts->registerCallControlId('poc-call-3', 'control-3');

        $request = Request::create(
            '/api/telnyx/webhook',
            'POST',
            content: json_encode([
                'data' => [
                    'id' => 'event-transcript-controller',
                    'event_type' => 'call.transcription',
                    'occurred_at' => '2026-06-13T10:00:00+00:00',
                    'payload' => [
                        'call_session_id' => 'session-3',
                        'call_leg_id' => 'leg-3',
                        'call_control_id' => 'control-3',
                        'transcription_data' => [
                            'transcription_track' => 'outbound',
                            'is_final' => true,
                            'transcript' => 'Transcript from controller path',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $response = $this->controller(new NullLogger())(
            $request,
            $repository,
            $entityManager,
            $projection,
            $this->browserCallReconciler(),
            $recordingProjection,
            $capture,
            $devTest,
            new SttProviderRegistry([$provider], 'telnyx'),
            $transcripts,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($provider->handled);

        $stored = $transcripts->fetchSince('poc-call-3', 0);
        self::assertCount(1, $stored['segments']);
        self::assertSame('Transcript from controller path', $stored['segments'][0]['text']);
        self::assertSame('customer', $stored['segments'][0]['speaker']);
    }

    public function testInboundSpeakEndedStartsCapture(): void
    {
        $repository = $this->createMock(TelnyxEventRepository::class);
        $repository->expects(self::once())->method('findOneByProviderEventId')->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $projection = $this->createMock(TelnyxCallProjectionService::class);
        $projection->expects(self::once())->method('project');
        $recordingProjection = $this->createMock(TelnyxRecordingProjectionService::class);
        $recordingProjection->expects(self::once())->method('project');
        $capture = $this->createMock(TelnyxCaptureService::class);
        $capture->expects(self::once())->method('startForInboundIntroCompleted');
        $capture->expects(self::never())->method('startForBridgedInboundLeg');
        $devTest = $this->createMock(DevTelnyxTranscriptionTestService::class);
        $devTest->expects(self::once())->method('handleWebhook')->willReturn(false);
        $reconciler = $this->browserCallReconciler();

        $response = $this->controller(new NullLogger())(
            $this->webhookRequest('event-speak-ended-capture', 'call.speak.ended'),
            $repository,
            $entityManager,
            $projection,
            $reconciler,
            $recordingProjection,
            $capture,
            $devTest,
            $this->providers(),
            new PocBrowserSoftphoneTranscriptService(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function controller(LoggerInterface $logger): TelnyxWebhookController
    {
        $controller = new TelnyxWebhookController(
            new TelnyxCallControlService(new MockHttpClient(), $logger, 'test-api-key'),
            new TelnyxCallStateService(new ArrayAdapter()),
            $this->clickToCall(false),
            new ClientStateService(),
            new CapturePolicyResolver(true, true),
            new TelnyxTranscriptionService(),
            $logger,
            '+14168880123',
            '+12892079888',
        );
        $controller->setContainer(new Container());

        return $controller;
    }

    private function webhookRequest(string $id, string $eventType): Request
    {
        return Request::create(
            '/api/telnyx/webhook',
            'POST',
            content: json_encode([
                'data' => [
                    'id' => $id,
                    'event_type' => $eventType,
                    'occurred_at' => '2026-06-13T10:00:00+00:00',
                    'payload' => [
                        'call_session_id' => 'session-1',
                        'call_leg_id' => 'leg-1',
                        'call_control_id' => 'control-1',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function clickToCall(bool $handled): ClickToCallService
    {
        $service = $this->createMock(ClickToCallService::class);
        $service->method('handleWebhook')->willReturn($handled);

        return $service;
    }

    private function browserCallReconciler(): BrowserCallEventReconcilerService
    {
        $callSessionManager = $this->createMock(EntityManagerInterface::class);
        $callSessionManager->method('getClassMetadata')
            ->willReturn(new \Doctrine\ORM\Mapping\ClassMetadata(\App\Entity\CallSession::class));
        $callSessionRegistry = $this->createMock(\Doctrine\Persistence\ManagerRegistry::class);
        $callSessionRegistry->method('getManagerForClass')->willReturn($callSessionManager);

        $browserSessionManager = $this->createMock(EntityManagerInterface::class);
        $browserSessionManager->method('getClassMetadata')
            ->willReturn(new \Doctrine\ORM\Mapping\ClassMetadata(\App\Entity\BrowserSoftphoneSession::class));
        $browserSessionRegistry = $this->createMock(\Doctrine\Persistence\ManagerRegistry::class);
        $browserSessionRegistry->method('getManagerForClass')->willReturn($browserSessionManager);

        return new BrowserCallEventReconcilerService(
            new \App\Repository\CallSessionRepository($callSessionRegistry),
            new \App\Repository\BrowserSoftphoneSessionRepository($browserSessionRegistry),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(\App\Service\CallEventEngineService::class),
            $this->createMock(\App\Service\CommunicationTimelineProjector::class),
            $this->createMock(\App\Service\AuditLogger::class),
            new NullLogger(),
        );
    }

    private function providers(): SttProviderRegistry
    {
        $provider = new class() implements SttProviderInterface {
            public function getName(): string
            {
                return 'telnyx';
            }

            public function submit(\App\Entity\TranscriptionJob $job): void
            {
            }
        };

        return new SttProviderRegistry([$provider], 'telnyx');
    }
}
