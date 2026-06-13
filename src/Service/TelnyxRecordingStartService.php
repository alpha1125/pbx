<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallAction;
use App\Entity\CallRecording;
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

    private function start(\App\Entity\CallLeg $leg): void
    {
        $session = $leg->getCallSession();
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
            ->setFormat($this->recordingFormat);

        $this->entityManager->persist($action);
        $this->entityManager->persist($recording);
        $this->entityManager->flush();

        try {
            $this->callControl->startRecording($callControlId, $this->recordingFormat);
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
}
