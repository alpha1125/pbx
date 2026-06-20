<?php

declare(strict_types=1);

namespace App\Controller;

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
use App\Service\DevTelnyxTranscriptionTestService;
use App\Service\TelnyxCaptureService;
use App\Service\BrowserCallEventReconcilerService;
use App\Service\TelnyxRecordingProjectionService;
use App\Service\TelnyxTranscriptionService;
use App\Transcription\SttProviderRegistry;
use App\Transcription\WebhookDrivenSttProviderInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TelnyxWebhookController extends AbstractController
{
    public function __construct(
        private readonly TelnyxCallControlService $callControl,
        private readonly TelnyxCallStateService $callState,
        private readonly ClickToCallService $clickToCall,
        private readonly ClientStateService $clientState,
        private readonly CapturePolicyResolver $policyResolver,
        private readonly TelnyxTranscriptionService $transcription,
        private readonly LoggerInterface $logger,
        private readonly string $forwardToNumber,
        private readonly string $fromNumber,
    ) {
    }

    #[Route('/api/telnyx/webhook', methods: ['POST'])]
    public function __invoke(
        Request $request,
        TelnyxEventRepository $repository,
        EntityManagerInterface $entityManager,
        TelnyxCallProjectionService $callProjection,
        BrowserCallEventReconcilerService $reconciler,
        TelnyxRecordingProjectionService $recordingProjection,
        TelnyxCaptureService $capture,
        DevTelnyxTranscriptionTestService $transcriptionTest,
        SttProviderRegistry $providers,
        PocBrowserSoftphoneTranscriptService $pocTranscripts,
    ): JsonResponse {
        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON payload.'], Response::HTTP_BAD_REQUEST);
        }

        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return $this->json(['ok' => false, 'error' => 'Missing required event data.'], Response::HTTP_BAD_REQUEST);
        }

        $providerEventId = $data['id'] ?? null;
        $eventType = $data['event_type'] ?? null;
        $eventPayload = $data['payload'] ?? null;
        $callControlId = is_array($eventPayload) ? ($eventPayload['call_control_id'] ?? null) : null;

        if (!is_string($providerEventId) || '' === $providerEventId || !is_string($eventType) || '' === $eventType) {
            return $this->json(['ok' => false, 'error' => 'Missing required event data.'], Response::HTTP_BAD_REQUEST);
        }

        if (null !== $callControlId && !is_string($callControlId)) {
            $callControlId = null;
        }

        if (null !== $repository->findOneByProviderEventId($providerEventId)) {
            return $this->json(['ok' => true, 'duplicate' => true]);
        }

        $event = new TelnyxEvent(
            $providerEventId,
            $eventType,
            $payload,
            $callControlId,
        );
        $entityManager->persist($event);

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            $callProjection->project($event, $data);

            // Phase 9K: Reconcile Telnyx webhook state with any pending browser events.
            if (null !== $event->getCallSession()) {
                $reconciler->reconcileWebhook($callProjection, $event, $data);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Telnyx call-state projection failed after webhook persistence.', [
                'provider_event_id' => $providerEventId,
                'event_type' => $eventType,
                'call_control_id' => $callControlId,
                'exception' => $exception,
            ]);
        }

        try {
            $recordingProjection->project($eventType, $data, $payload);
        } catch (\Throwable $exception) {
            $this->logger->error('Telnyx recording projection failed after webhook persistence.', [
                'provider_event_id' => $providerEventId,
                'event_type' => $eventType,
                'call_control_id' => $callControlId,
                'exception' => $exception,
            ]);
        }

        if ($this->isTranscriptionEvent($eventType)) {
            try {
                $provider = $providers->get('telnyx');
                if ($provider instanceof WebhookDrivenSttProviderInterface) {
                    $provider->handleWebhook($payload, $event);
                }
                $this->publishPocTranscriptSegment($event, $data, $pocTranscripts);
            } catch (\Throwable $exception) {
                $this->logger->error('Telnyx transcription provider processing failed after webhook persistence.', [
                    'provider_event_id' => $providerEventId,
                    'event_type' => $eventType,
                    'call_control_id' => $callControlId,
                    'exception' => $exception,
                ]);
            }
        }

        if ('call.bridged' === $eventType && is_array($eventPayload)) {
            try {
                $capture->startForBridgedInboundLeg($eventPayload);
            } catch (\Throwable $exception) {
                $this->logger->error('Telnyx recording start failed after webhook persistence.', [
                    'provider_event_id' => $providerEventId,
                    'call_control_id' => $callControlId,
                    'exception' => $exception,
                ]);
            }
        }

        if ('call.answered' === $eventType && is_array($eventPayload)) {
            try {
                $capture->startWhenBothLegsAnswered($eventPayload);
            } catch (\Throwable $exception) {
                $this->logger->error('Telnyx fallback recording start failed after webhook persistence.', [
                    'provider_event_id' => $providerEventId,
                    'call_control_id' => $callControlId,
                    'exception' => $exception,
                ]);
            }
        }

        if ('call.speak.ended' === $eventType && is_array($eventPayload)) {
            try {
                $capture->startForInboundIntroCompleted($eventPayload);
            } catch (\Throwable $exception) {
                $this->logger->error('Telnyx inbound capture start failed after intro playback.', [
                    'provider_event_id' => $providerEventId,
                    'call_control_id' => $callControlId,
                    'exception' => $exception,
                ]);
            }
        }

        try {
            if ($transcriptionTest->handleWebhook($eventType, $eventPayload ?? [])) {
                return $this->json(['ok' => true]);
            }
            $this->handleCallControlEvent($eventType, $eventPayload, $providerEventId);
        } catch (\Throwable $exception) {
            $this->logger->error('Telnyx Call Control action failed after webhook persistence.', [
                'provider_event_id' => $providerEventId,
                'event_type' => $eventType,
                'call_control_id' => $callControlId,
                'exception' => $exception,
            ]);
        }

        return $this->json(['ok' => true]);
    }

    private function isTranscriptionEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'call.recording.saved',
            'call.recording.error',
            'call.recording.transcription.saved',
            'call.transcription',
            'call.transcription.saved',
            'call.transcription.error',
        ], true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function publishPocTranscriptSegment(
        TelnyxEvent $event,
        array $data,
        PocBrowserSoftphoneTranscriptService $transcripts,
    ): void {
        $callControlId = $this->stringValue($data['payload'] ?? [], 'call_control_id')
            ?? $event->getCallControlId()
            ?? $event->getCallLeg()?->getCallControlId();
        if (null === $callControlId || '' === trim($callControlId)) {
            $this->logger->debug('Poc browser softphone transcript mirror skipped: no call-control id.', [
                'provider_event_id' => $event->getProviderEventId(),
                'event_type' => $event->getEventType(),
            ]);
            return;
        }

        $callSessionId = $transcripts->resolveCallSessionIdForCallControlId($callControlId);
        if (null === $callSessionId) {
            $this->logger->debug('Poc browser softphone transcript mirror skipped: unknown call-control id.', [
                'provider_event_id' => $event->getProviderEventId(),
                'call_control_id' => $callControlId,
            ]);
            return;
        }

        $session = $event->getCallSession();

        $text = $this->extractTranscriptText($data['payload'] ?? []);
        if (null === $text || '' === trim($text)) {
            $this->logger->debug('Poc browser softphone transcript mirror skipped: empty transcript text.', [
                'provider_event_id' => $event->getProviderEventId(),
                'call_control_id' => $callControlId,
                'call_session_id' => $callSessionId,
            ]);
            return;
        }

        $speaker = $this->speakerFromTranscriptPayload(
            $session,
            $event->getCallLeg(),
            $data['payload'] ?? [],
        );
        $occurredAt = $this->occurredAtFromWebhook($event->getPayload()) ?? $event->getReceivedAt();
        $isFinal = $this->isFinalTranscriptPayload($data['payload'] ?? []);

        $transcripts->recordSegment(
            $callSessionId,
            $speaker,
            $text,
            $occurredAt,
            $isFinal,
            $event->getProviderEventId(),
        );

        $this->logger->debug('Poc browser softphone transcript mirrored.', [
            'provider_event_id' => $event->getProviderEventId(),
            'call_control_id' => $callControlId,
            'call_session_id' => $callSessionId,
            'speaker' => $speaker,
            'text' => $text,
            'is_final' => $isFinal,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractTranscriptText(array $payload): ?string
    {
        $text = $this->stringValue($payload, 'transcription_text')
            ?? $this->stringValue($payload, 'text')
            ?? $this->stringValue($payload, 'transcript');
        if (null !== $text) {
            return $text;
        }

        $transcriptionData = $payload['transcription_data'] ?? null;
        if (is_array($transcriptionData)) {
            return $this->stringValue($transcriptionData, 'transcript')
                ?? $this->stringValue($transcriptionData, 'text');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isFinalTranscriptPayload(array $payload): bool
    {
        $transcriptionData = $payload['transcription_data'] ?? null;
        if (is_array($transcriptionData) && array_key_exists('is_final', $transcriptionData)) {
            return true === $transcriptionData['is_final'];
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function speakerFromTranscriptPayload(
        ?CallSession $session,
        ?CallLeg $leg,
        array $payload,
    ): string {
        $track = $this->transcriptionTrackFromPayload($payload);
        if ('inbound' === $track) {
            return 'csr';
        }

        if ('outbound' === $track) {
            return 'customer';
        }

        if (null !== $leg) {
            return match ($leg->getDirection()) {
                'outgoing' => 'csr',
                'incoming' => 'customer',
                default => match ($leg->getRole()) {
                    CallLeg::ROLE_AGENT, CallLeg::ROLE_VENDOR, CallLeg::ROLE_SYSTEM => 'csr',
                    CallLeg::ROLE_CALLER, CallLeg::ROLE_CUSTOMER => 'customer',
                    default => 'customer',
                },
            };
        }

        if (null !== $session) {
            return CallSession::CALL_MODE_BROWSER === $session->getCallMode() ? 'csr' : 'customer';
        }

        return 'customer';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function transcriptionTrackFromPayload(array $payload): ?string
    {
        $transcriptionData = $payload['transcription_data'] ?? null;
        if (!is_array($transcriptionData)) {
            return null;
        }

        $track = $transcriptionData['transcription_track'] ?? null;
        if (!is_string($track) || '' === trim($track)) {
            return null;
        }

        return strtolower(trim($track));
    }

    private function occurredAtFromWebhook(array $webhook): ?\DateTimeImmutable
    {
        $data = $webhook['data'] ?? null;
        $occurredAt = is_array($data) ? ($data['occurred_at'] ?? null) : null;
        if (!is_string($occurredAt) || '' === trim($occurredAt)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($occurredAt);
        } catch (\Throwable) {
            return null;
        }
    }

    private function handleCallControlEvent(
        string $eventType,
        mixed $eventPayload,
        string $providerEventId,
    ): void {
        if (!is_array($eventPayload)) {
            return;
        }

        if ($this->clickToCall->handleWebhook($eventType, $eventPayload, $providerEventId)) {
            return;
        }

        $callControlId = $this->stringValue($eventPayload, 'call_control_id');
        $direction = $this->stringValue($eventPayload, 'direction');

        if ('call.initiated' === $eventType && 'incoming' === $direction) {
            $callSessionId = $this->requiredStringValue($eventPayload, 'call_session_id');
            $callControlId = $this->requiredStringValue($eventPayload, 'call_control_id');
            $this->callState->storeInbound(
                $callSessionId,
                $callControlId,
                $this->requiredStringValue($eventPayload, 'call_leg_id'),
                $this->requiredStringValue($eventPayload, 'connection_id'),
            );
            $this->callControl->answer($callControlId, sprintf('inbound-forward:%s:answer', $callSessionId));

            return;
        }

        if (
            'call.answered' === $eventType
            && null !== $callControlId
            && $this->isInboundEvent($eventPayload, $callControlId)
        ) {
            $policy = $this->policyResolver->defaultForContext(\App\Entity\CallSession::FLOW_TYPE_INBOUND_FORWARD);
            $this->callControl->speak(
                $callControlId,
                $this->transcription->forwardingDisclosureMessage($policy),
                null,
                sprintf('inbound-forward:%s:speak-intro', $this->requiredStringValue($eventPayload, 'call_session_id')),
            );

            return;
        }

        if (
            'call.speak.ended' === $eventType
            && null !== $callControlId
            && $this->isInboundEvent($eventPayload, $callControlId)
        ) {
            $callSessionId = $this->requiredStringValue($eventPayload, 'call_session_id');
            $state = $this->callState->getInbound($callSessionId);
            if (null === $state) {
                throw new \RuntimeException(sprintf(
                    'No inbound call state found for Telnyx session "%s".',
                    $callSessionId,
                ));
            }
            if ('' === trim($this->forwardToNumber)) {
                throw new \RuntimeException('TELNYX_FORWARD_TO_NUMBER is missing.');
            }
            if ('' === trim($this->fromNumber)) {
                throw new \RuntimeException('TELNYX_FROM_NUMBER is missing.');
            }

            $clientState = base64_encode((string) json_encode(
                ['inbound_call_session_id' => $callSessionId],
                JSON_THROW_ON_ERROR,
            ));
            $this->callControl->dial(
                $state['connection_id'],
                $this->fromNumber,
                $this->forwardToNumber,
                $clientState,
                null,
                sprintf('inbound-forward:%s:dial', $callSessionId),
            );

            return;
        }

        if ('call.hangup' === $eventType) {
            $callSessionId = $this->inboundSessionIdFromClientState(
                $this->stringValue($eventPayload, 'client_state'),
            );
            if (null === $callSessionId) {
                return;
            }

            $state = $this->callState->getInbound($callSessionId);
            if (null === $state) {
                $this->logger->debug('Ignoring outbound hangup without inbound call state.', [
                    'provider_event_id' => $providerEventId,
                    'call_control_id' => $callControlId,
                    'inbound_call_session_id' => $callSessionId,
                ]);

                return;
            }

            $this->callControl->hangup($state['call_control_id'], sprintf('inbound-forward:%s:hangup', $callSessionId));

            return;
        }

        if ('call.answered' === $eventType && null !== $callControlId) {
            $callSessionId = $this->inboundSessionIdFromClientState(
                $this->stringValue($eventPayload, 'client_state'),
            );
            if (null === $callSessionId) {
                $this->logger->debug('Ignoring outbound answered call without forwarding state.', [
                    'provider_event_id' => $providerEventId,
                    'call_control_id' => $callControlId,
                ]);

                return;
            }

            $state = $this->callState->getInbound($callSessionId);
            if (null === $state) {
                throw new \RuntimeException(sprintf(
                    'No inbound call state found for Telnyx session "%s".',
                    $callSessionId,
                ));
            }

            $this->callControl->bridge(
                $state['call_control_id'],
                $callControlId,
                sprintf('inbound-forward:%s:bridge', $callSessionId),
            );
        }
    }

    /**
     * Telnyx only includes direction on call.initiated. Match later events to
     * the inbound leg state saved when that event arrived.
     *
     * @param array<string, mixed> $payload
     */
    private function isInboundEvent(array $payload, string $callControlId): bool
    {
        $callSessionId = $this->stringValue($payload, 'call_session_id');
        if (null === $callSessionId) {
            return false;
        }

        $state = $this->callState->getInbound($callSessionId);

        return null !== $state && $state['call_control_id'] === $callControlId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredStringValue(array $payload, string $key): string
    {
        $value = $this->stringValue($payload, $key);
        if (null === $value) {
            throw new \RuntimeException(sprintf('Telnyx webhook is missing "%s".', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    private function inboundSessionIdFromClientState(?string $clientState): ?string
    {
        $state = $this->clientState->decode($clientState);

        $callSessionId = $state['inbound_call_session_id'] ?? null;

        return is_string($callSessionId) && '' !== $callSessionId ? $callSessionId : null;
    }
}
