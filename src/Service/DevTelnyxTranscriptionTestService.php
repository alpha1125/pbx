<?php

declare(strict_types=1);

namespace App\Service;

use App\Capture\CapturePolicy;
use App\Entity\CallAction;
use App\Entity\CallLeg;
use App\Entity\CallSession;
use App\Repository\CallLegRepository;
use App\Repository\CallSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class DevTelnyxTranscriptionTestService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CallSessionRepository $sessionRepository,
        private readonly CallLegRepository $legRepository,
        private readonly TelnyxCallControlService $callControl,
        private readonly TelnyxCaptureService $capture,
        private readonly CapturePolicyResolver $policyResolver,
        private readonly ClientStateService $clientState,
        private readonly TelnyxTranscriptionService $transcription,
        private readonly string $fromNumber,
        private readonly string $connectionId,
    ) {
    }

    public function start(string $targetNumber, ?string $targetName, ?bool $recordAudio, ?bool $transcribeAudio): CallSession
    {
        if ('' === trim($this->connectionId)) {
            throw new \RuntimeException('TELNYX_CONNECTION_ID is missing.');
        }
        if ('' === trim($this->fromNumber)) {
            throw new \RuntimeException('TELNYX_FROM_NUMBER is missing.');
        }

        $policy = $this->policyResolver->resolve(
            $recordAudio,
            $transcribeAudio,
            CallSession::FLOW_TYPE_TRANSCRIPTION_TEST,
        );

        $session = (new CallSession('local-transcription-test-'.Uuid::v7()->toRfc4122()))
            ->setProvider('telnyx')
            ->setFlowType(CallSession::FLOW_TYPE_TRANSCRIPTION_TEST)
            ->setInboundFrom($this->fromNumber)
            ->setInboundTo($targetNumber)
            ->setStatus('initiated');
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $clientState = $this->clientState->encode([
            'flow' => CallSession::FLOW_TYPE_TRANSCRIPTION_TEST,
            'root_call_session_id' => $session->getProviderSessionId(),
            'recordAudio' => $policy->recordAudio,
            'transcribeAudio' => $policy->transcribeAudio,
            'targetName' => $targetName,
        ]);

        $action = (new CallAction('dial_transcription_test'))
            ->setCallSession($session)
            ->setRequestPayload([
                'connection_id' => $this->connectionId,
                'from' => $this->fromNumber,
                'to' => $targetNumber,
                'client_state' => $clientState,
                'capturePolicy' => $policy->toArray(),
                'command_id' => sprintf('transcription-test:%s:dial', $session->getProviderSessionId()),
            ]);
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        try {
            $response = $this->callControl->dial(
                $this->connectionId,
                $this->fromNumber,
                $targetNumber,
                $clientState,
                45,
                sprintf('transcription-test:%s:dial', $session->getProviderSessionId()),
            );
            $action->setStatus('succeeded')->setResponsePayload($response);
        } catch (\Throwable $exception) {
            $action->setStatus('failed')->setErrorMessage($exception->getMessage());
            $session->setStatus('failed')->touch();
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();

        return $session;
    }

    public function handleWebhook(string $eventType, array $payload): bool
    {
        $state = $this->clientState->decode($this->stringValue($payload, 'client_state'));
        $session = $this->resolveRootSession($payload, $state);
        if (null === $session || CallSession::FLOW_TYPE_TRANSCRIPTION_TEST !== $session->getFlowType()) {
            return false;
        }

        return match ($eventType) {
            'call.answered' => $this->handleAnswered($session, $payload, $state),
            'call.speak.ended' => $this->handleSpeakEnded($session, $payload, $state),
            'call.hangup' => $this->handleHangup($session),
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $state
     */
    private function handleAnswered(CallSession $session, array $payload, ?array $state): bool
    {
        if ('transcription_test_intro' === ($state['stage'] ?? null)) {
            return true;
        }

        $callControlId = $this->stringValue($payload, 'call_control_id');
        if (null === $callControlId) {
            return true;
        }

        $policy = $this->policyResolver->fromClientState($state, CallSession::FLOW_TYPE_TRANSCRIPTION_TEST);
        $introState = $this->clientState->encode([
            'flow' => CallSession::FLOW_TYPE_TRANSCRIPTION_TEST,
            'root_call_session_id' => $session->getProviderSessionId(),
            'recordAudio' => $policy->recordAudio,
            'transcribeAudio' => $policy->transcribeAudio,
            'targetName' => $state['targetName'] ?? null,
            'stage' => 'transcription_test_intro',
        ]);
        $disclosure = $this->transcription->disclosureMessage($policy);

        $action = (new CallAction('speak_transcription_test_intro'))
            ->setCallSession($session)
            ->setRequestPayload([
                'call_control_id' => $callControlId,
                'payload' => $disclosure,
                'client_state' => $introState,
                'command_id' => sprintf('transcription-test:%s:intro', $session->getProviderSessionId()),
            ]);
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        try {
            $response = $this->callControl->speak(
                $callControlId,
                $disclosure,
                $introState,
                sprintf('transcription-test:%s:intro', $session->getProviderSessionId()),
            );
            $action->setStatus('succeeded')->setResponsePayload($response);
        } catch (\Throwable $exception) {
            $action->setStatus('failed')->setErrorMessage($exception->getMessage());
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $state
     */
    private function handleSpeakEnded(CallSession $session, array $payload, ?array $state): bool
    {
        if ('transcription_test_intro' !== ($state['stage'] ?? null)) {
            return true;
        }

        $providerLegId = $this->stringValue($payload, 'call_leg_id');
        if (null === $providerLegId) {
            return true;
        }

        $leg = $this->legRepository->findOneByProviderLegId($providerLegId);
        if (null === $leg) {
            return true;
        }

        $policy = $this->policyResolver->fromClientState($state, CallSession::FLOW_TYPE_TRANSCRIPTION_TEST);
        $this->capture->applyPolicyToLeg(
            $leg,
            $policy,
            sprintf('transcription-test:%s', $session->getProviderSessionId()),
        );

        return true;
    }

    private function handleHangup(CallSession $session): bool
    {
        $session->touch();
        $this->entityManager->flush();

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $state
     */
    private function resolveRootSession(array $payload, ?array $state): ?CallSession
    {
        $rootProviderSessionId = $state['root_call_session_id'] ?? null;
        if (is_string($rootProviderSessionId) && '' !== trim($rootProviderSessionId)) {
            return $this->sessionRepository->findOneByProviderSessionId($rootProviderSessionId);
        }

        $providerSessionId = $this->stringValue($payload, 'call_session_id');
        if (null === $providerSessionId) {
            return null;
        }

        $session = $this->sessionRepository->findOneByProviderSessionId($providerSessionId);

        return $session?->getParentCallSession() ?? $session;
    }
    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : null;
    }
}
