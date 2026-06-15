<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallAction;
use App\Entity\CallLeg;
use App\Entity\CallSession;
use App\Entity\ClickToCallRequest;
use App\Repository\CallLegRepository;
use App\Repository\CallSessionRepository;
use App\Repository\ClickToCallRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class ClickToCallService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClickToCallRequestRepository $requestRepository,
        private readonly CallSessionRepository $sessionRepository,
        private readonly CallLegRepository $legRepository,
        private readonly TelnyxCallControlService $callControl,
        private readonly TelnyxCaptureService $capture,
        private readonly CapturePolicyResolver $capturePolicyResolver,
        private readonly ClientStateService $clientState,
        private readonly LoggerInterface $logger,
        private readonly string $fromNumber,
        private readonly string $connectionId,
        private readonly string $defaultAgentNumber,
    ) {
    }

    public function start(string $targetNumber, ?string $targetName = null, ?string $agentNumber = null): ClickToCallRequest
    {
        $agentNumber = $agentNumber ?? trim($this->defaultAgentNumber);
        if ('' === trim($agentNumber)) {
            throw new \RuntimeException('CLICK_TO_CALL_AGENT_NUMBER is missing.');
        }
        if ('' === trim($this->connectionId)) {
            throw new \RuntimeException('TELNYX_CONNECTION_ID is missing.');
        }
        if ('' === trim($this->fromNumber)) {
            throw new \RuntimeException('TELNYX_FROM_NUMBER is missing.');
        }

        $rootSession = (new CallSession('local-click-'.Uuid::v7()->toRfc4122()))
            ->setProvider('telnyx')
            ->setFlowType(CallSession::FLOW_TYPE_CLICK_TO_CALL)
            ->setInboundFrom($agentNumber)
            ->setInboundTo($targetNumber)
            ->setStatus('initiated');

        $request = (new ClickToCallRequest($agentNumber, $targetNumber, $this->fromNumber, $this->connectionId))
            ->setTargetName($targetName)
            ->setCallSession($rootSession)
            ->setClientStateToken(Uuid::v7()->toRfc4122());

        $this->entityManager->persist($rootSession);
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $clientState = $this->clientState->encode([
            'flow' => CallSession::FLOW_TYPE_CLICK_TO_CALL,
            'clickToCallRequestId' => $request->getId(),
            'leg' => 'agent',
            'targetName' => $targetName,
            'token' => $request->getClientStateToken(),
        ]);

        $action = (new CallAction('dial_agent'))
            ->setCallSession($rootSession)
            ->setStatus('attempted')
            ->setRequestPayload([
                'connection_id' => $this->connectionId,
                'from' => $this->fromNumber,
                'to' => $agentNumber,
                'client_state' => $clientState,
                'command_id' => $this->commandId($request, 'dial-agent'),
            ]);
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        try {
            $response = $this->callControl->dial(
                $this->connectionId,
                $this->fromNumber,
                $agentNumber,
                $clientState,
                45,
                $this->commandId($request, 'dial-agent'),
            );
            $request
                ->setStatus(ClickToCallRequest::STATUS_DIALING_AGENT)
                ->setAgentCallLegId($this->extractString($response, ['data.call_leg_id', 'data.id', 'call_leg_id']) ?? $request->getAgentCallLegId())
                ->touch();
            $action->setStatus('succeeded')->setResponsePayload($response);
        } catch (\Throwable $exception) {
            $request
                ->setStatus(ClickToCallRequest::STATUS_FAILED)
                ->setErrorMessage($exception->getMessage())
                ->touch();
            $action->setStatus('failed')->setErrorMessage($exception->getMessage());
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();

        return $request;
    }

    public function handleWebhook(string $eventType, array $payload, string $providerEventId): bool
    {
        $request = $this->resolveRequest($payload);
        if (null === $request) {
            return false;
        }

        return match ($eventType) {
            'call.initiated' => $this->handleInitiated($request, $payload),
            'call.answered' => $this->handleAnswered($request, $payload),
            'call.speak.ended' => $this->handleSpeakEnded($request, $payload),
            'call.bridged', 'call.bridge' => $this->handleBridged($request),
            'call.hangup' => $this->handleHangup($request, $payload, $providerEventId),
            default => true,
        };
    }

    private function handleInitiated(ClickToCallRequest $request, array $payload): bool
    {
        $state = $this->clientState->decode($this->stringValue($payload, 'client_state'));
        $legKind = is_array($state) ? ($state['leg'] ?? null) : null;

        if ('agent' === $legKind) {
            $this->linkCurrentLeg($request, $payload, CallLeg::ROLE_AGENT, 1);
            $request
                ->setStatus(ClickToCallRequest::STATUS_DIALING_AGENT)
                ->setAgentCallControlId($this->stringValue($payload, 'call_control_id') ?? $request->getAgentCallControlId())
                ->setAgentCallLegId($this->stringValue($payload, 'call_leg_id') ?? $request->getAgentCallLegId())
                ->touch();
            $this->entityManager->flush();

            return true;
        }

        if ('target' === $legKind) {
            $this->linkCurrentLeg($request, $payload, CallLeg::ROLE_CUSTOMER, 2);
            $request
                ->setStatus(ClickToCallRequest::STATUS_DIALING_TARGET)
                ->setTargetCallControlId($this->stringValue($payload, 'call_control_id') ?? $request->getTargetCallControlId())
                ->setTargetCallLegId($this->stringValue($payload, 'call_leg_id') ?? $request->getTargetCallLegId())
                ->touch();
            $this->entityManager->flush();

            return true;
        }

        return true;
    }

    private function handleAnswered(ClickToCallRequest $request, array $payload): bool
    {
        $providerLegId = $this->stringValue($payload, 'call_leg_id');
        if (null !== $providerLegId && $providerLegId === $request->getAgentCallLegId()) {
            if (ClickToCallRequest::STATUS_SPEAKING_INTRO !== $request->getStatus()) {
                $request->setStatus(ClickToCallRequest::STATUS_AGENT_ANSWERED)->touch();
                $this->entityManager->flush();

                $targetName = $request->getTargetName() ?? 'the contact';
                $introState = $this->clientState->encode([
                    'flow' => CallSession::FLOW_TYPE_CLICK_TO_CALL,
                    'clickToCallRequestId' => $request->getId(),
                    'leg' => 'agent_intro',
                    'token' => $request->getClientStateToken(),
                ]);
                $action = (new CallAction('speak_intro'))
                    ->setCallSession($request->getCallSession())
                    ->setRequestPayload([
                        'call_control_id' => $request->getAgentCallControlId() ?? $this->requiredString($payload, 'call_control_id'),
                        'payload' => sprintf('Connecting call to %s.', $targetName),
                        'client_state' => $introState,
                        'command_id' => $this->commandId($request, 'agent-intro'),
                    ]);
                $this->entityManager->persist($action);
                $this->entityManager->flush();

                $response = $this->callControl->speak(
                    $request->getAgentCallControlId() ?? $this->requiredString($payload, 'call_control_id'),
                    sprintf('Connecting call to %s.', $targetName),
                    $introState,
                    $this->commandId($request, 'agent-intro'),
                );
                $action->setStatus('succeeded')->setResponsePayload($response);
                $request->setStatus(ClickToCallRequest::STATUS_SPEAKING_INTRO)->touch();
                $this->entityManager->flush();
            }

            return true;
        }

        if (null !== $providerLegId && $providerLegId === $request->getTargetCallLegId()) {
            $request
                ->setStatus(ClickToCallRequest::STATUS_TARGET_ANSWERED)
                ->setTargetCallControlId($this->stringValue($payload, 'call_control_id') ?? $request->getTargetCallControlId())
                ->touch();

            $action = (new CallAction('bridge'))
                ->setCallSession($request->getCallSession())
                ->setRequestPayload([
                    'call_control_id' => $request->getAgentCallControlId(),
                    'target_call_control_id' => $request->getTargetCallControlId(),
                    'command_id' => $this->commandId($request, 'bridge'),
                ]);
            $this->entityManager->persist($action);
            $this->entityManager->flush();

            try {
                $response = $this->callControl->bridge(
                    $request->getAgentCallControlId() ?? throw new \RuntimeException('Missing agent call control id for bridge.'),
                    $request->getTargetCallControlId() ?? throw new \RuntimeException('Missing target call control id for bridge.'),
                    $this->commandId($request, 'bridge'),
                );
                $action->setStatus('succeeded')->setResponsePayload($response);
                $request->setBridgeStartedAt($request->getBridgeStartedAt() ?? new \DateTimeImmutable())->touch();
            } catch (\Throwable $exception) {
                $action->setStatus('failed')->setErrorMessage($exception->getMessage());
                $request
                    ->setStatus(ClickToCallRequest::STATUS_FAILED)
                    ->setErrorMessage($exception->getMessage())
                    ->touch();
                $this->entityManager->flush();

                throw $exception;
            }

            $this->entityManager->flush();

            return true;
        }

        return true;
    }

    private function handleSpeakEnded(ClickToCallRequest $request, array $payload): bool
    {
        $state = $this->clientState->decode($this->stringValue($payload, 'client_state'));
        if (!is_array($state) || 'agent_intro' !== ($state['leg'] ?? null)) {
            return true;
        }

        $clientState = $this->clientState->encode([
            'flow' => CallSession::FLOW_TYPE_CLICK_TO_CALL,
            'clickToCallRequestId' => $request->getId(),
            'leg' => 'target',
            'token' => $request->getClientStateToken(),
        ]);

        $action = (new CallAction('dial_target'))
            ->setCallSession($request->getCallSession())
            ->setRequestPayload([
                'connection_id' => $request->getConnectionId(),
                'from' => $request->getFromNumber(),
                'to' => $request->getTargetNumber(),
                'client_state' => $clientState,
                'command_id' => $this->commandId($request, 'dial-target'),
            ]);
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        try {
            $response = $this->callControl->dial(
                $request->getConnectionId(),
                $request->getFromNumber(),
                $request->getTargetNumber(),
                $clientState,
                45,
                $this->commandId($request, 'dial-target'),
            );
            $request
                ->setStatus(ClickToCallRequest::STATUS_DIALING_TARGET)
                ->setTargetCallLegId($this->extractString($response, ['data.call_leg_id', 'data.id', 'call_leg_id']) ?? $request->getTargetCallLegId())
                ->touch();
            $action->setStatus('succeeded')->setResponsePayload($response);
        } catch (\Throwable $exception) {
            $request
                ->setStatus(ClickToCallRequest::STATUS_FAILED)
                ->setErrorMessage($exception->getMessage())
                ->touch();
            $action->setStatus('failed')->setErrorMessage($exception->getMessage());
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();

        return true;
    }

    private function handleBridged(ClickToCallRequest $request): bool
    {
        $request
            ->setStatus(ClickToCallRequest::STATUS_BRIDGED)
            ->setBridgeStartedAt($request->getBridgeStartedAt() ?? new \DateTimeImmutable())
            ->touch();

        if (null === $request->getRecordingStartedAt()) {
            $policy = $this->capturePolicyResolver->defaultForContext(CallSession::FLOW_TYPE_CLICK_TO_CALL);
            $agentLeg = null !== $request->getAgentCallLegId()
                ? $this->legRepository->findOneByProviderLegId($request->getAgentCallLegId())
                : null;
            if (null !== $agentLeg) {
                $this->capture->applyPolicyToLeg(
                    $agentLeg,
                    $policy,
                    sprintf('click-to-call:%d', $request->getId()),
                );
                if ($policy->recordAudio) {
                    $request
                        ->setStatus(ClickToCallRequest::STATUS_RECORDING)
                        ->setRecordingStartedAt(new \DateTimeImmutable())
                        ->touch();
                }
            }
        }

        $this->entityManager->flush();

        return true;
    }

    private function handleHangup(ClickToCallRequest $request, array $payload, string $providerEventId): bool
    {
        $providerLegId = $this->stringValue($payload, 'call_leg_id');
        $hangupCause = $this->stringValue($payload, 'hangup_cause') ?? 'unknown_hangup';
        $isAgentLeg = null !== $providerLegId && $providerLegId === $request->getAgentCallLegId();
        $isTargetLeg = null !== $providerLegId && $providerLegId === $request->getTargetCallLegId();

        if ($isTargetLeg && !in_array($request->getStatus(), [ClickToCallRequest::STATUS_BRIDGED, ClickToCallRequest::STATUS_RECORDING, ClickToCallRequest::STATUS_COMPLETED], true)) {
            try {
                if (null !== $request->getAgentCallControlId()) {
                    $speakAction = (new CallAction('speak_target_failed'))
                        ->setCallSession($request->getCallSession())
                        ->setRequestPayload([
                            'call_control_id' => $request->getAgentCallControlId(),
                            'payload' => 'The call could not be connected.',
                            'command_id' => $this->commandId($request, 'target-failed-speak'),
                        ]);
                    $hangupAction = (new CallAction('hangup_agent'))
                        ->setCallSession($request->getCallSession())
                        ->setRequestPayload([
                            'call_control_id' => $request->getAgentCallControlId(),
                            'command_id' => $this->commandId($request, 'target-failed-hangup-agent'),
                        ]);
                    $this->entityManager->persist($speakAction);
                    $this->entityManager->persist($hangupAction);
                    $this->entityManager->flush();

                    $speakResponse = $this->callControl->speak(
                        $request->getAgentCallControlId(),
                        'The call could not be connected.',
                        null,
                        $this->commandId($request, 'target-failed-speak'),
                    );
                    $hangupResponse = $this->callControl->hangup(
                        $request->getAgentCallControlId(),
                        $this->commandId($request, 'target-failed-hangup-agent'),
                    );
                    $speakAction->setStatus('succeeded')->setResponsePayload($speakResponse);
                    $hangupAction->setStatus('succeeded')->setResponsePayload($hangupResponse);
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Click-to-call failure cleanup failed.', [
                    'click_to_call_request_id' => $request->getId(),
                    'provider_event_id' => $providerEventId,
                    'exception' => $exception,
                ]);
            }

            $request
                ->setStatus(ClickToCallRequest::STATUS_FAILED)
                ->setErrorMessage($hangupCause)
                ->touch();
            $this->entityManager->flush();

            return true;
        }

        if ($isAgentLeg && !in_array($request->getStatus(), [ClickToCallRequest::STATUS_BRIDGED, ClickToCallRequest::STATUS_RECORDING, ClickToCallRequest::STATUS_COMPLETED], true)) {
            if (null !== $request->getTargetCallControlId()) {
                try {
                    $action = (new CallAction('hangup_target'))
                        ->setCallSession($request->getCallSession())
                        ->setRequestPayload([
                            'call_control_id' => $request->getTargetCallControlId(),
                            'command_id' => $this->commandId($request, 'agent-hangup-target'),
                        ]);
                    $this->entityManager->persist($action);
                    $this->entityManager->flush();
                    $response = $this->callControl->hangup(
                        $request->getTargetCallControlId(),
                        $this->commandId($request, 'agent-hangup-target'),
                    );
                    $action->setStatus('succeeded')->setResponsePayload($response);
                } catch (\Throwable $exception) {
                    $this->logger->error('Click-to-call target hangup after agent hangup failed.', [
                        'click_to_call_request_id' => $request->getId(),
                        'provider_event_id' => $providerEventId,
                        'exception' => $exception,
                    ]);
                }
            }

            $request
                ->setStatus(ClickToCallRequest::STATUS_FAILED)
                ->setErrorMessage($hangupCause)
                ->touch();
            $this->entityManager->flush();

            return true;
        }

        $request
            ->setStatus(ClickToCallRequest::STATUS_COMPLETED)
            ->setErrorMessage($request->getErrorMessage())
            ->touch();
        $this->entityManager->flush();

        return true;
    }

    private function linkCurrentLeg(ClickToCallRequest $request, array $payload, string $role, int $dialOrder): void
    {
        $providerSessionId = $this->stringValue($payload, 'call_session_id');
        $providerLegId = $this->stringValue($payload, 'call_leg_id');
        if (null === $providerSessionId || null === $providerLegId || null === $request->getCallSession()) {
            return;
        }

        $session = $this->sessionRepository->findOneByProviderSessionId($providerSessionId);
        $leg = $this->legRepository->findOneByProviderLegId($providerLegId);
        if (null === $session || null === $leg) {
            return;
        }

        $session
            ->setParentCallSession($request->getCallSession())
            ->setFlowType(CallSession::FLOW_TYPE_CLICK_TO_CALL)
            ->touch();
        $request->getCallSession()?->setFlowType(CallSession::FLOW_TYPE_CLICK_TO_CALL)->touch();
        $leg
            ->setRole($role)
            ->setDialOrder($dialOrder)
            ->touch();
    }

    private function resolveRequest(array $payload): ?ClickToCallRequest
    {
        $state = $this->clientState->decode($this->stringValue($payload, 'client_state'));
        if (is_array($state) && CallSession::FLOW_TYPE_CLICK_TO_CALL === ($state['flow'] ?? null)) {
            $requestId = $state['clickToCallRequestId'] ?? null;
            if ((is_int($requestId) || (is_string($requestId) && ctype_digit($requestId))) && null !== ($request = $this->requestRepository->find((int) $requestId))) {
                return $request;
            }

            $token = $state['token'] ?? null;
            if (is_string($token) && '' !== $token) {
                $request = $this->requestRepository->findOneByClientStateToken($token);
                if (null !== $request) {
                    return $request;
                }
            }
        }

        return $this->requestRepository->findOneByAnyLegId($this->stringValue($payload, 'call_leg_id'))
            ?? $this->requestRepository->findOneByAnyCallControlId($this->stringValue($payload, 'call_control_id'));
    }

    private function commandId(ClickToCallRequest $request, string $suffix): string
    {
        return sprintf('click-to-call:%d:%s', $request->getId(), $suffix);
    }


    /**
     * @param array<string, mixed>|null $response
     * @param list<string> $paths
     */
    private function extractString(?array $response, array $paths): ?string
    {
        if (null === $response) {
            return null;
        }

        foreach ($paths as $path) {
            $value = $response;
            foreach (explode('.', $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    continue 2;
                }
                $value = $value[$segment];
            }

            if (is_string($value) && '' !== trim($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = $this->stringValue($payload, $key);
        if (null === $value) {
            throw new \RuntimeException(sprintf('Missing "%s" in Telnyx click-to-call webhook payload.', $key));
        }

        return $value;
    }
}
