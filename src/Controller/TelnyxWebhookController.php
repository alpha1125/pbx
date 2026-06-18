<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TelnyxEvent;
use App\Repository\TelnyxEventRepository;
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
