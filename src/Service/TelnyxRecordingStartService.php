<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallAction;
use App\Entity\CallLeg;
use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Entity\ClickToCallRequest;
use App\Repository\CallActionRepository;
use App\Repository\CallLegRepository;
use App\Repository\CallSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

class TelnyxRecordingStartService
{
    public function __construct(
        private readonly TelnyxCallControlService $callControl,
        private readonly CallLegRepository $legRepository,
        private readonly CallSessionRepository $sessionRepository,
        private readonly CallActionRepository $actionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly bool $recordingEnabled,
        private readonly string $recordingFormat,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function startForBridgedInboundLeg(array $payload): void
    {
        if (!$this->recordingEnabled) {
            return;
        }

        $providerLegId = $this->stringValue($payload, 'call_leg_id');
        $callControlId = $this->stringValue($payload, 'call_control_id');
        if (null === $providerLegId || null === $callControlId) {
            return;
        }

        $leg = $this->legRepository->findOneByProviderLegId($providerLegId);
        if (null === $leg || 'incoming' !== $leg->getDirection()) {
            return;
        }

        $this->start($leg);
    }

    /** @param array<string, mixed> $payload */
    public function startWhenBothLegsAnswered(array $payload): void
    {
        if (!$this->recordingEnabled) {
            return;
        }

        $providerSessionId = $this->stringValue($payload, 'call_session_id');
        if (null === $providerSessionId) {
            return;
        }

        $session = $this->sessionRepository->findOneByProviderSessionId($providerSessionId);
        $rootSession = $session?->getParentCallSession() ?? $session;
        if (null === $rootSession || !$this->legRepository->hasAnsweredOutboundLeg($rootSession)) {
            return;
        }

        $inboundLeg = $this->legRepository->findInboundLegForRootSession($rootSession);
        if (null !== $inboundLeg) {
            $this->start($inboundLeg);
        }
    }

    public function startForClickToCallRequest(ClickToCallRequest $request): void
    {
        if (!$this->recordingEnabled) {
            return;
        }

        $agentCallLegId = $request->getAgentCallLegId();
        if (null === $agentCallLegId) {
            return;
        }

        $agentLeg = $this->legRepository->findOneByProviderLegId($agentCallLegId);
        if (null === $agentLeg || null === $request->getCallSession()) {
            return;
        }

        $this->start($agentLeg, $request->getCallSession(), $this->channelMappingFor($request->getCallSession()));
    }

    /**
     * @param array<string, mixed>|null $channelMapping
     */
    private function start(CallLeg $leg, ?CallSession $session = null, ?array $channelMapping = null): void
    {
        $session ??= $leg->getCallSession()->getParentCallSession() ?? $leg->getCallSession();
        if ($this->actionRepository->hasActionForSession($session, 'record_start')) {
            return;
        }

        $callControlId = $leg->getCallControlId();
        if (null === $callControlId) {
            return;
        }

        $action = (new CallAction('record_start'))
            ->setCallSession($session)
            ->setCallLeg($leg)
            ->setRequestPayload([
                'call_control_id' => $callControlId,
                'format' => $this->recordingFormat,
                'channels' => 'dual',
                'recording_track' => 'both',
            ]);
        $recording = (new CallRecording($session))
            ->setCallLeg($leg)
            ->setFormat($this->recordingFormat)
            ->setChannelMapping($channelMapping ?? $this->channelMappingFor($session));

        $this->entityManager->persist($action);
        $this->entityManager->persist($recording);
        $this->entityManager->flush();

        try {
            $this->callControl->startRecording(
                $callControlId,
                $this->recordingFormat,
                sprintf('record-start:%s:%s', $session->getId() ?? 'session', $callControlId),
            );
            $action->setStatus('succeeded');
        } catch (\Throwable $exception) {
            $action
                ->setStatus('failed')
                ->setErrorMessage($exception->getMessage());
            $recording->setStatus('failed')->touch();
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();
    }

    /** @param array<string, mixed> $payload */
    private function stringValue(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function channelMappingFor(CallSession $session): ?array
    {
        return match ($session->getFlowType()) {
            CallSession::FLOW_TYPE_CLICK_TO_CALL => [
                'flowType' => CallSession::FLOW_TYPE_CLICK_TO_CALL,
                'ch_0' => 'agent',
                'ch_1' => 'customer',
                'confidence' => 'assumed_agent_dialed_first_verify_with_test_recording',
                'notes' => 'For click-to-call, agent is dialed first and customer second. Verify left/right channel assignment empirically.',
            ],
            CallSession::FLOW_TYPE_INBOUND_FORWARD => [
                'flowType' => CallSession::FLOW_TYPE_INBOUND_FORWARD,
                'ch_0' => 'caller',
                'ch_1' => 'forwarded_party',
                'confidence' => 'observed',
            ],
            default => null,
        };
    }
}
