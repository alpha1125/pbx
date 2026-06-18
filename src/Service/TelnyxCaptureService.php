<?php

declare(strict_types=1);

namespace App\Service;

use App\Capture\CapturePolicy;
use App\Entity\CallAction;
use App\Entity\CallLeg;
use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Entity\TranscriptionJob;
use App\Repository\CallActionRepository;
use App\Repository\CallLegRepository;
use App\Repository\CallRecordingRepository;
use App\Repository\CallSessionRepository;
use App\Repository\TranscriptionJobRepository;
use App\Transcription\Provider\TelnyxTranscriptionConfiguration;
use Doctrine\ORM\EntityManagerInterface;

class TelnyxCaptureService
{
    public function __construct(
        private readonly TelnyxCallControlService $callControl,
        private readonly CallLegRepository $legRepository,
        private readonly CallSessionRepository $sessionRepository,
        private readonly CallActionRepository $actionRepository,
        private readonly CallRecordingRepository $recordingRepository,
        private readonly TranscriptionJobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CapturePolicyResolver $policyResolver,
        private readonly CallCaptureControlService $captureControl,
        private readonly string $recordingFormat,
        private readonly string $recordingChannels,
        private readonly TelnyxTranscriptionConfiguration $transcriptionConfiguration,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function startForBridgedInboundLeg(array $payload): void
    {
        $providerLegId = $this->stringValue($payload, 'call_leg_id');
        if (null === $providerLegId) {
            return;
        }

        $leg = $this->legRepository->findOneByProviderLegId($providerLegId);
        if (null === $leg || 'incoming' !== $leg->getDirection()) {
            return;
        }

        $this->applyPolicyToLeg(
            $leg,
            $this->policyResolver->defaultForContext(CallSession::FLOW_TYPE_INBOUND_FORWARD),
            sprintf('inbound-forward:%s', $leg->getCallSession()->getParentCallSession()?->getProviderSessionId() ?? $leg->getCallSession()->getProviderSessionId()),
        );
    }

    /** @param array<string, mixed> $payload */
    public function startForInboundIntroCompleted(array $payload): void
    {
        $providerLegId = $this->stringValue($payload, 'call_leg_id');
        if (null === $providerLegId) {
            return;
        }

        $leg = $this->legRepository->findOneByProviderLegId($providerLegId);
        if (null === $leg || 'incoming' !== $leg->getDirection()) {
            return;
        }

        $rootSession = $leg->getCallSession()->getParentCallSession() ?? $leg->getCallSession();
        if (CallSession::FLOW_TYPE_INBOUND_FORWARD !== $rootSession->getFlowType()) {
            return;
        }

        $this->applyPolicyToLeg(
            $leg,
            $this->policyResolver->defaultForContext(CallSession::FLOW_TYPE_INBOUND_FORWARD),
            sprintf('inbound-forward:%s', $rootSession->getProviderSessionId()),
        );
    }

    /** @param array<string, mixed> $payload */
    public function startWhenBothLegsAnswered(array $payload): void
    {
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
        if (null === $inboundLeg) {
            return;
        }

        $this->applyPolicyToLeg(
            $inboundLeg,
            $this->policyResolver->defaultForContext(CallSession::FLOW_TYPE_INBOUND_FORWARD),
            sprintf('inbound-forward:%s', $rootSession->getProviderSessionId()),
        );
    }

    public function applyPolicyToLeg(CallLeg $leg, CapturePolicy $policy, string $commandPrefix): void
    {
        $session = $leg->getCallSession()->getParentCallSession() ?? $leg->getCallSession();
        if (!$policy->shouldCaptureAnything()) {
            return;
        }

        if ($policy->recordAudio) {
            $this->captureControl->startRecording($session, $leg, $policy, $commandPrefix);
        }

        if ($policy->transcribeAudio) {
            $this->captureControl->startTranscription($session, $leg, $policy, $commandPrefix);
        }
    }

    private function startRecording(CallSession $session, CallLeg $leg, CapturePolicy $policy, string $commandId): void
    {
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
                'channels' => $this->recordingChannels,
                'recording_track' => $this->transcriptionConfiguration->getTrack(),
                'command_id' => $commandId,
                'capturePolicy' => $policy->toArray(),
            ]);
        $recording = $this->recordingRepository->findRequested($session, $leg)
            ?? (new CallRecording($session))
                ->setCallLeg($leg);

        $recording
            ->setFormat($this->recordingFormat)
            ->setChannelMapping($recording->getChannelMapping() ?? $this->channelMappingFor($session))
            ->setRawPayload([
                'recording_track' => $this->transcriptionConfiguration->getTrack(),
                'channels' => $this->recordingChannels,
                'capturePolicy' => $policy->toArray(),
            ])
            ->touch();

        $this->entityManager->persist($action);
        if (null === $recording->getId()) {
            $this->entityManager->persist($recording);
        }
        $this->entityManager->flush();

        try {
            $response = $this->callControl->startRecording($callControlId, [
                'format' => $this->recordingFormat,
                'channels' => $this->recordingChannels,
                'recording_track' => $this->transcriptionConfiguration->getTrack(),
                'command_id' => $commandId,
            ]);
            $action->setStatus('succeeded')->setResponsePayload($response);
        } catch (\Throwable $exception) {
            $action->setStatus('failed')->setErrorMessage($exception->getMessage());
            $recording->setStatus('failed')->touch();
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();
    }

    private function startTranscription(CallSession $session, CallLeg $leg, CapturePolicy $policy, string $commandId): void
    {
        if ($this->actionRepository->hasActionForSession($session, 'transcription_start')) {
            return;
        }

        $callControlId = $leg->getCallControlId();
        if (null === $callControlId) {
            return;
        }

        $job = $this->jobRepository->findOneForSessionAndProvider($session, 'telnyx')
            ?? (new TranscriptionJob())
                ->setCallSession($session)
                ->setCallLeg($leg)
                ->setProvider('telnyx');
        $recording = $job->getCallRecording() ?? $this->recordingRepository->findRequested($session, $leg);

        $job
            ->setCallRecording($recording)
            ->setProvider('telnyx')
            ->setProviderModel($this->transcriptionConfiguration->getModel())
            ->setProviderConfig($this->transcriptionConfiguration->toProviderConfig())
            ->setProviderStatus('transcription_start_attempted')
            ->setStatus('submitted')
            ->setSubmittedAt($job->getSubmittedAt() ?? new \DateTimeImmutable())
            ->setChannelMapping($job->getChannelMapping() ?? $this->channelMappingFor($session))
            ->touch();

        $action = (new CallAction('transcription_start'))
            ->setCallSession($session)
            ->setCallLeg($leg)
            ->setRequestPayload([
                'call_control_id' => $callControlId,
                'transcription_engine' => $this->transcriptionConfiguration->getEngine(),
                'transcription_tracks' => $this->transcriptionConfiguration->getTrack(),
                'command_id' => $commandId,
                'capturePolicy' => $policy->toArray(),
            ]);

        if (null === $job->getId()) {
            $this->entityManager->persist($job);
        }
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        try {
            $response = $this->callControl->startTranscription($callControlId, [
                ...$this->transcriptionConfiguration->toTranscriptionStartPayload(),
                'command_id' => $commandId,
            ]);
            $job
                ->setProviderStatus('transcription_started')
                ->setRawProviderResponse($response)
                ->touch();
            $action->setStatus('succeeded')->setResponsePayload($response);
        } catch (\Throwable $exception) {
            $job
                ->setStatus('failed')
                ->setProviderStatus('failed_to_start')
                ->setErrorMessage($exception->getMessage())
                ->touch();
            $action->setStatus('failed')->setErrorMessage($exception->getMessage());
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();
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
            CallSession::FLOW_TYPE_TRANSCRIPTION_TEST => [
                'flowType' => CallSession::FLOW_TYPE_TRANSCRIPTION_TEST,
                'ch_0' => 'target',
                'ch_1' => 'system_or_remote_party',
                'confidence' => 'assumed_single_outbound_leg_verify_with_live_call',
            ],
            default => null,
        };
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
