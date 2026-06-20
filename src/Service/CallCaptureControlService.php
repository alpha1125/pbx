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
use App\Repository\CallRecordingRepository;
use App\Repository\TranscriptionJobRepository;
use App\Transcription\Provider\TelnyxTranscriptionConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class CallCaptureControlService
{
    public function __construct(
        private readonly TelnyxCallControlService $callControl,
        private readonly CallActionRepository $actionRepository,
        private readonly CallRecordingRepository $recordingRepository,
        private readonly TranscriptionJobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly string $recordingFormat,
        private readonly string $recordingChannels,
        private readonly TelnyxTranscriptionConfiguration $transcriptionConfiguration,
    ) {
    }

    public function playConsentMessage(
        CallSession $session,
        ?CallLeg $leg,
        string $message,
        string $commandPrefix,
        ?string $callControlId = null,
    ): void
    {
        $callControlId = $this->resolveCallControlId($session, $leg, $callControlId, 'play consent');

        if ($this->actionRepository->hasActionForSession($session, 'consent_play')) {
            return;
        }

        $session->setRecordingState(CallSession::RECORDING_STATE_CONSENT_PLAYING)->touch();
        $action = (new CallAction('consent_play'))
            ->setCallSession($session)
            ->setCallLeg($leg)
            ->setRequestPayload([
                'call_control_id' => $callControlId,
                'payload' => $message,
                'command_id' => $this->commandId($session, $commandPrefix, 'consent'),
            ]);
        $this->entityManager->persist($action);
        $this->auditLogger->log(
            $session->getTenant(),
            'call_session',
            (string) ($session->getId() ?? 'new'),
            'call.capture_consent_play_started',
            null,
            ['message' => $message, 'recordingState' => $session->getRecordingState()],
            ['callLegId' => null !== $leg ? $leg->getId() : null],
        );
        $this->entityManager->flush();

        try {
            $response = $this->callControl->speak(
                $callControlId,
                $message,
                null,
                $this->commandId($session, $commandPrefix, 'consent'),
            );
            $action->setStatus('succeeded')->setResponsePayload($response);
            $session->setRecordingState(CallSession::RECORDING_STATE_INACTIVE)->touch();
            $this->auditLogger->log(
                $session->getTenant(),
                'call_session',
                (string) ($session->getId() ?? 'new'),
                'call.capture_consent_played',
                ['recordingState' => CallSession::RECORDING_STATE_CONSENT_PLAYING],
                ['recordingState' => CallSession::RECORDING_STATE_INACTIVE],
                ['callLegId' => null !== $leg ? $leg->getId() : null],
            );
        } catch (\Throwable $exception) {
            $action->setStatus('failed')->setErrorMessage($exception->getMessage());
            $session->setRecordingState(CallSession::RECORDING_STATE_FAILED)->touch();
            $this->auditLogger->log(
                $session->getTenant(),
                'call_session',
                (string) ($session->getId() ?? 'new'),
                'call.capture_consent_play_failed',
                ['recordingState' => CallSession::RECORDING_STATE_CONSENT_PLAYING],
                ['recordingState' => CallSession::RECORDING_STATE_FAILED],
                ['callLegId' => $leg->getId(), 'error' => $exception->getMessage()],
            );
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();
    }

    public function startRecording(
        CallSession $session,
        ?CallLeg $leg,
        CapturePolicy $policy,
        string $commandPrefix,
        ?string $callControlId = null,
    ): void
    {
        $callControlId = $this->resolveCallControlId($session, $leg, $callControlId, 'start recording');
        if ($this->actionRepository->hasActionForSession($session, 'record_start')) {
            return;
        }

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

        $action = (new CallAction('record_start'))
            ->setCallSession($session)
            ->setCallLeg($leg)
            ->setRequestPayload([
                'call_control_id' => $callControlId,
                'format' => $this->recordingFormat,
                'channels' => $this->recordingChannels,
                'recording_track' => $this->transcriptionConfiguration->getTrack(),
                'command_id' => $this->commandId($session, $commandPrefix, 'record-start'),
                'capturePolicy' => $policy->toArray(),
            ]);

        $this->entityManager->persist($action);
        if (null === $recording->getId()) {
            $this->entityManager->persist($recording);
        }
        $this->entityManager->flush();

        try {
            $this->logger->info('Requesting Telnyx recording start.', [
                'callSessionId' => $session->getId(),
                'callLegId' => $leg?->getId(),
                'callControlId' => $callControlId,
                'capturePolicy' => $policy->toArray(),
                'commandId' => $this->commandId($session, $commandPrefix, 'record-start'),
            ]);
            $response = $this->callControl->startRecording($callControlId, [
                'format' => $this->recordingFormat,
                'channels' => $this->recordingChannels,
                'recording_track' => $this->transcriptionConfiguration->getTrack(),
                'command_id' => $this->commandId($session, $commandPrefix, 'record-start'),
            ]);
            $this->logger->info('Telnyx recording start returned.', [
                'callSessionId' => $session->getId(),
                'callLegId' => $leg?->getId(),
                'callControlId' => $callControlId,
                'response' => $response,
            ]);
            $action->setStatus('succeeded')->setResponsePayload($response);
            $session->setRecordingState(CallSession::RECORDING_STATE_ACTIVE)->touch();
            $this->auditLogger->log(
                $session->getTenant(),
                'call_session',
                (string) ($session->getId() ?? 'new'),
                'call.capture_recording_started',
                ['recordingState' => CallSession::RECORDING_STATE_INACTIVE],
                ['recordingState' => CallSession::RECORDING_STATE_ACTIVE],
                ['callLegId' => null !== $leg ? $leg->getId() : null],
            );
        } catch (\Throwable $exception) {
            $action->setStatus('failed')->setErrorMessage($exception->getMessage());
            $recording->setStatus('failed')->touch();
            $session->setRecordingState(CallSession::RECORDING_STATE_FAILED)->touch();
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();
    }

    public function stopRecording(
        CallSession $session,
        ?CallLeg $leg,
        string $commandPrefix,
        ?string $callControlId = null,
    ): void
    {
        $callControlId = $this->resolveCallControlId($session, $leg, $callControlId, 'stop recording');
        if ($this->actionRepository->hasActionForSession($session, 'record_stop')) {
            return;
        }

        $session->setRecordingState(CallSession::RECORDING_STATE_STOPPING)->touch();
        $action = (new CallAction('record_stop'))
            ->setCallSession($session)
            ->setCallLeg($leg)
            ->setRequestPayload([
                'call_control_id' => $callControlId,
                'command_id' => $this->commandId($session, $commandPrefix, 'record-stop'),
            ]);
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        try {
            $response = $this->callControl->stopRecording(
                $callControlId,
                $this->commandId($session, $commandPrefix, 'record-stop'),
            );
            $action->setStatus('succeeded')->setResponsePayload($response);
            $session->setRecordingState(CallSession::RECORDING_STATE_STOPPED)->touch();
            $this->auditLogger->log(
                $session->getTenant(),
                'call_session',
                (string) ($session->getId() ?? 'new'),
                'call.capture_recording_stopped',
                ['recordingState' => CallSession::RECORDING_STATE_STOPPING],
                ['recordingState' => CallSession::RECORDING_STATE_STOPPED],
                ['callLegId' => null !== $leg ? $leg->getId() : null],
            );
        } catch (\Throwable $exception) {
            $action->setStatus('failed')->setErrorMessage($exception->getMessage());
            $session->setRecordingState(CallSession::RECORDING_STATE_FAILED)->touch();
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();
    }

    public function startTranscription(
        CallSession $session,
        ?CallLeg $leg,
        CapturePolicy $policy,
        string $commandPrefix,
        ?string $callControlId = null,
    ): void
    {
        $callControlId = $this->resolveCallControlId($session, $leg, $callControlId, 'start transcription');
        if ($this->actionRepository->hasActionForSession($session, 'transcription_start')) {
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
                'command_id' => $this->commandId($session, $commandPrefix, 'transcription-start'),
                'capturePolicy' => $policy->toArray(),
            ]);

        if (null === $job->getId()) {
            $this->entityManager->persist($job);
        }
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        try {
            $this->logger->info('Requesting Telnyx transcription start.', [
                'callSessionId' => $session->getId(),
                'callLegId' => $leg?->getId(),
                'callControlId' => $callControlId,
                'capturePolicy' => $policy->toArray(),
                'commandId' => $this->commandId($session, $commandPrefix, 'transcription-start'),
            ]);
            $response = $this->callControl->startTranscription($callControlId, [
                ...$this->transcriptionConfiguration->toTranscriptionStartPayload(),
                'command_id' => $this->commandId($session, $commandPrefix, 'transcription-start'),
            ]);
            $this->logger->info('Telnyx transcription start returned.', [
                'callSessionId' => $session->getId(),
                'callLegId' => $leg?->getId(),
                'callControlId' => $callControlId,
                'response' => $response,
            ]);
            $job
                ->setProviderStatus('transcription_started')
                ->setRawProviderResponse($response)
                ->touch();
            $action->setStatus('succeeded')->setResponsePayload($response);
            $session->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_ACTIVE)->touch();
            $this->auditLogger->log(
                $session->getTenant(),
                'call_session',
                (string) ($session->getId() ?? 'new'),
                'call.capture_transcription_started',
                ['transcriptionState' => CallSession::TRANSCRIPTION_STATE_INACTIVE],
                ['transcriptionState' => CallSession::TRANSCRIPTION_STATE_ACTIVE],
                ['callLegId' => null !== $leg ? $leg->getId() : null],
            );
        } catch (\Throwable $exception) {
            $job
                ->setStatus('failed')
                ->setProviderStatus('failed_to_start')
                ->setErrorMessage($exception->getMessage())
                ->touch();
            $action->setStatus('failed')->setErrorMessage($exception->getMessage());
            $session->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_FAILED)->touch();
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();
    }

    public function stopTranscription(
        CallSession $session,
        ?CallLeg $leg,
        string $commandPrefix,
        ?string $callControlId = null,
    ): void
    {
        $callControlId = $this->resolveCallControlId($session, $leg, $callControlId, 'stop transcription');
        if ($this->actionRepository->hasActionForSession($session, 'transcription_stop')) {
            return;
        }

        $session->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_STOPPING)->touch();
        $action = (new CallAction('transcription_stop'))
            ->setCallSession($session)
            ->setCallLeg($leg)
            ->setRequestPayload([
                'call_control_id' => $callControlId,
                'command_id' => $this->commandId($session, $commandPrefix, 'transcription-stop'),
            ]);
        $this->entityManager->persist($action);
        $this->entityManager->flush();

        try {
            $response = $this->callControl->stopTranscription(
                $callControlId,
                $this->commandId($session, $commandPrefix, 'transcription-stop'),
            );
            $action->setStatus('succeeded')->setResponsePayload($response);
            $session->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_STOPPED)->touch();
            $this->auditLogger->log(
                $session->getTenant(),
                'call_session',
                (string) ($session->getId() ?? 'new'),
                'call.capture_transcription_stopped',
                ['transcriptionState' => CallSession::TRANSCRIPTION_STATE_STOPPING],
                ['transcriptionState' => CallSession::TRANSCRIPTION_STATE_STOPPED],
                ['callLegId' => null !== $leg ? $leg->getId() : null],
            );
        } catch (\Throwable $exception) {
            $action->setStatus('failed')->setErrorMessage($exception->getMessage());
            $session->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_FAILED)->touch();
            $this->entityManager->flush();

            throw $exception;
        }

        $this->entityManager->flush();
    }

    private function resolveCallControlId(
        CallSession $session,
        ?CallLeg $leg,
        ?string $callControlId,
        string $operation,
    ): string {
        $resolved = null !== $callControlId ? trim($callControlId) : (null !== $leg ? trim((string) $leg->getCallControlId()) : '');
        if ('' === $resolved) {
            throw new \RuntimeException(sprintf('Call control ID is required to %s.', $operation));
        }

        return $resolved;
    }

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

    private function commandId(CallSession $session, string $prefix, string $suffix): string
    {
        return sprintf('%s:%s:%s', $prefix, (string) ($session->getId() ?? 'new'), $suffix);
    }
}
