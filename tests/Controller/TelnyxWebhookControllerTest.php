<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\TelnyxWebhookController;
use App\Entity\TelnyxEvent;
use App\Repository\TelnyxEventRepository;
use App\Service\TelnyxCallControlService;
use App\Service\TelnyxCallProjectionService;
use App\Service\TelnyxCallStateService;
use App\Service\ClientStateService;
use App\Service\ClickToCallService;
use App\Service\TelnyxRecordingProjectionService;
use App\Service\TelnyxRecordingStartService;
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
        $recordingStart = $this->createMock(TelnyxRecordingStartService::class);
        $recordingStart->expects(self::never())->method('startForBridgedInboundLeg');

        $response = $this->controller(new NullLogger())(
            $this->webhookRequest('event-duplicate', 'call.initiated'),
            $repository,
            $entityManager,
            $projection,
            $recordingProjection,
            $recordingStart,
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
        $recordingStart = $this->createMock(TelnyxRecordingStartService::class);
        $recordingStart->expects(self::never())->method('startForBridgedInboundLeg');
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
            $recordingProjection,
            $recordingStart,
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
        $recordingStart = $this->createMock(TelnyxRecordingStartService::class);
        $recordingStart->expects(self::never())->method('startForBridgedInboundLeg');
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
            $recordingProjection,
            $recordingStart,
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
}
