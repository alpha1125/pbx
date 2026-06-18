<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Entity\CallSummary;
use App\Entity\CallTranscript;
use App\Entity\CommunicationTimelineItem;
use App\Entity\Job;
use App\Entity\Invoice;
use App\Entity\Quote;
use App\Entity\Property;
use App\Repository\CallRecordingRepository;
use App\Repository\CallSessionRepository;
use App\Repository\CallSummaryRepository;
use App\Repository\CallTranscriptRepository;
use App\Repository\CommunicationTimelineItemRepository;
use Doctrine\ORM\EntityManagerInterface;

class CommunicationTimelineProjector implements InvoiceTimelineProjectorInterface
{
    public function __construct(
        private readonly CallSessionRepository $callSessions,
        private readonly CallRecordingRepository $recordings,
        private readonly CallTranscriptRepository $transcripts,
        private readonly CallSummaryRepository $summaries,
        private readonly CallInsightService $callInsights,
        private readonly CommunicationTimelineItemRepository $timeline,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function syncProperty(Property $property): void
    {
        $sessions = $this->callSessions->findByTenantAndProperty($property->getTenant(), $property);
        $durationMap = $this->callSessions->findBilledDurationSeconds($sessions);

        foreach ($sessions as $session) {
            $this->upsertCall($session, $durationMap[$session->getId()] ?? null);
        }

        foreach ($this->recordings->findBySessions($sessions) as $recording) {
            $this->upsertRecording($recording);
        }

        foreach ($this->transcripts->findBySessions($sessions) as $transcript) {
            $this->upsertTranscript($transcript);
        }

        foreach ($this->summaries->findBySessions($sessions) as $summary) {
            $this->upsertSummary($summary);
        }

        $this->entityManager->flush();
    }

    public function recordQuoteEvent(Quote $quote, string $action, ?string $bodyText = null, ?array $metadata = null): void
    {
        if (null === $quote->getId() || null === $quote->getTenant()) {
            return;
        }

        $item = $this->findOrCreate(
            sprintf('quote_event:%d:%s', $quote->getId(), $action),
            $quote->getTenant(),
            CommunicationTimelineItem::TYPE_QUOTE_EVENT,
            new \DateTimeImmutable(),
        );

        $item
            ->setProperty($quote->getProperty())
            ->setContact($quote->getContact())
            ->setEstimate($quote->getEstimate())
            ->setQuote($quote)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setBodyText($bodyText ?? sprintf('Quote event: %s.', $action))
            ->setMetadata(array_merge([
                'action' => $action,
                'quoteNumber' => $quote->getQuoteNumber(),
                'status' => $quote->getStatus(),
            ], $metadata ?? []))
            ->touch();

        $this->entityManager->flush();
    }

    public function recordInvoiceEvent(Invoice $invoice, string $action, ?string $bodyText = null, ?array $metadata = null): void
    {
        if (null === $invoice->getId() || null === $invoice->getTenant()) {
            return;
        }

        $item = $this->findOrCreate(
            sprintf('invoice_event:%d:%s', $invoice->getId(), $action),
            $invoice->getTenant(),
            CommunicationTimelineItem::TYPE_INVOICE_EVENT,
            new \DateTimeImmutable(),
        );

        $item
            ->setProperty($invoice->getProperty())
            ->setContact($invoice->getContact())
            ->setQuote($invoice->getQuote())
            ->setInvoice($invoice)
            ->setOccurredAt(new \DateTimeImmutable())
            ->setBodyText($bodyText ?? sprintf('Invoice event: %s.', $action))
            ->setMetadata(array_merge([
                'action' => $action,
                'invoiceNumber' => $invoice->getInvoiceNumber(),
                'status' => $invoice->getStatus(),
            ], $metadata ?? []))
            ->touch();

        $this->entityManager->flush();
    }

    public function recordJobEvent(Job $job, string $action, ?string $bodyText = null, ?array $metadata = null): void
    {
        if (null === $job->getId() || null === $job->getTenant()) {
            return;
        }

        $item = $this->findOrCreate(
            sprintf('job_event:%d:%s', $job->getId(), $action),
            $job->getTenant(),
            CommunicationTimelineItem::TYPE_STATUS_CHANGE,
            new \DateTimeImmutable(),
        );

        $item
            ->setProperty($job->getProperty())
            ->setContact($job->getContact())
            ->setQuote($job->getQuote())
            ->setInvoice($job->getInvoice())
            ->setOccurredAt(new \DateTimeImmutable())
            ->setBodyText($bodyText ?? sprintf('Job event: %s.', $action))
            ->setMetadata(array_merge([
                'action' => $action,
                'jobId' => $job->getId(),
                'jobTitle' => $job->getTitle(),
                'status' => $job->getStatus(),
                'assignedTo' => $job->getAssignedTo()?->getDisplayName(),
                'scheduledStartAt' => $job->getScheduledStartAt()?->format(DATE_ATOM),
                'scheduledEndAt' => $job->getScheduledEndAt()?->format(DATE_ATOM),
                'completedAt' => $job->getCompletedAt()?->format(DATE_ATOM),
            ], $metadata ?? []))
            ->touch();

        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function recordCallEvent(CallSession $session, string $action, ?string $bodyText = null, ?array $metadata = null): void
    {
        if (null === $session->getId() || null === $session->getTenant()) {
            return;
        }

        $item = $this->findOrCreate(
            sprintf('call_event:%d:%s', $session->getId(), $action),
            $session->getTenant(),
            CommunicationTimelineItem::TYPE_STATUS_CHANGE,
            $session->getLastEventAt() ?? $session->getStartedAt() ?? $session->getCreatedAt(),
        );

        $item
            ->setProperty($session->getProperty())
            ->setContact($session->getContact())
            ->setEstimate($session->getEstimate())
            ->setQuote($session->getQuote())
            ->setInvoice($session->getInvoice())
            ->setRfqInvitation($session->getRfqInvitation())
            ->setCallSession($session)
            ->setOccurredAt($session->getLastEventAt() ?? $session->getStartedAt() ?? $session->getCreatedAt())
            ->setBodyText($bodyText ?? sprintf('Call event: %s.', $action))
            ->setMetadata(array_merge([
                'action' => $action,
                'status' => $session->getStatus(),
                'callState' => $session->getCallState(),
                'callMode' => $session->getCallMode(),
                'flowType' => $session->getFlowType(),
                'hangupCause' => $session->getHangupCause(),
                'hangupSource' => $session->getHangupSource(),
            ], $metadata ?? []))
            ->touch($session->getUpdatedAt());

        $this->entityManager->flush();
    }

    private function upsertCall(CallSession $session, ?int $durationSeconds): void
    {
        if (null === $session->getId() || null === $session->getTenant()) {
            return;
        }

        $item = $this->findOrCreate(
            sprintf('call_session:%d', $session->getId()),
            $session->getTenant(),
            CommunicationTimelineItem::TYPE_CALL,
            $session->getStartedAt() ?? $session->getCreatedAt(),
        );

        $item
            ->setProperty($session->getProperty())
            ->setContact($session->getContact())
            ->setEstimate($session->getEstimate())
            ->setQuote($session->getQuote())
            ->setInvoice($session->getInvoice())
            ->setRfqInvitation($session->getRfqInvitation())
            ->setCallSession($session)
            ->setOccurredAt($session->getStartedAt() ?? $session->getCreatedAt())
            ->setBodyText($this->callBodyText($session))
            ->setMetadata([
                'direction' => $this->callDirection($session),
                'durationSeconds' => $durationSeconds ?? $this->callDurationSeconds($session),
                'outcome' => $session->getHangupCause() ?: $session->getStatus(),
                'flowType' => $session->getFlowType(),
                'status' => $session->getStatus(),
                'from' => $session->getInboundFrom(),
                'to' => $session->getInboundTo(),
                'linkedContact' => $session->getContact()?->getDisplayName(),
            ])
            ->touch($session->getUpdatedAt());
    }

    private function upsertRecording(CallRecording $recording): void
    {
        if (null === $recording->getId() || null === $recording->getCallSession()->getTenant()) {
            return;
        }

        $session = $recording->getCallSession();
        $item = $this->findOrCreate(
            sprintf('call_recording:%d', $recording->getId()),
            $session->getTenant(),
            CommunicationTimelineItem::TYPE_RECORDING,
            $recording->getImportedAt() ?? $recording->getRecordingEndedAt() ?? $recording->getCreatedAt(),
        );

        $item
            ->setProperty($session->getProperty())
            ->setContact($session->getContact())
            ->setEstimate($session->getEstimate())
            ->setQuote($session->getQuote())
            ->setInvoice($session->getInvoice())
            ->setRfqInvitation($session->getRfqInvitation())
            ->setCallSession($session)
            ->setCallRecording($recording)
            ->setOccurredAt($recording->getImportedAt() ?? $recording->getRecordingEndedAt() ?? $recording->getCreatedAt())
            ->setBodyText('Call recording ready.')
            ->setMetadata([
                'status' => $recording->getStatus(),
                'format' => $recording->getFormat(),
                'durationSeconds' => $recording->getDurationSeconds(),
                'contentType' => $recording->getContentType(),
            ])
            ->touch();
    }

    private function upsertTranscript(CallTranscript $transcript): void
    {
        if (null === $transcript->getId()) {
            return;
        }

        $session = $transcript->getCallSession();
        $tenant = $session?->getTenant();
        if (null === $session || null === $tenant) {
            return;
        }

        $item = $this->findOrCreate(
            sprintf('call_transcript:%d', $transcript->getId()),
            $tenant,
            CommunicationTimelineItem::TYPE_TRANSCRIPT,
            $transcript->getCompletedAt() ?? $transcript->getCreatedAt(),
        );

        $body = $transcript->getTranscriptText();
        if (null !== $body && strlen($body) > 500) {
            $body = substr($body, 0, 497).'...';
        }

        $item
            ->setProperty($session->getProperty())
            ->setContact($session->getContact())
            ->setEstimate($session->getEstimate())
            ->setQuote($session->getQuote())
            ->setInvoice($session->getInvoice())
            ->setRfqInvitation($session->getRfqInvitation())
            ->setCallSession($session)
            ->setCallRecording($transcript->getCallRecording())
            ->setCallTranscript($transcript)
            ->setOccurredAt($transcript->getCompletedAt() ?? $transcript->getCreatedAt())
            ->setBodyText($body ?: 'Transcript generated.')
            ->setMetadata([
                'status' => $transcript->getStatus(),
                'provider' => $transcript->getProvider(),
                'model' => $transcript->getModel(),
                'language' => $transcript->getLanguage(),
                'durationSeconds' => $transcript->getDurationSeconds(),
                'aiInsights' => $this->callInsights->buildForTranscript($transcript),
            ])
            ->touch($transcript->getUpdatedAt());
    }

    private function upsertSummary(CallSummary $summary): void
    {
        if (null === $summary->getId()) {
            return;
        }

        $session = $summary->getCallSession();
        $tenant = $session?->getTenant();
        if (null === $session || null === $tenant) {
            return;
        }

        $item = $this->findOrCreate(
            sprintf('call_summary:%d', $summary->getId()),
            $tenant,
            CommunicationTimelineItem::TYPE_SUMMARY,
            $summary->getUpdatedAt(),
        );

        $item
            ->setProperty($session->getProperty())
            ->setContact($session->getContact())
            ->setEstimate($session->getEstimate())
            ->setQuote($session->getQuote())
            ->setInvoice($session->getInvoice())
            ->setRfqInvitation($session->getRfqInvitation())
            ->setCallSession($session)
            ->setCallRecording($summary->getCallRecording())
            ->setCallTranscript($summary->getCallTranscript())
            ->setCallSummary($summary)
            ->setOccurredAt($summary->getUpdatedAt())
            ->setBodyText($summary->getSummaryText() ?: 'Call summary generated.')
            ->setMetadata([
                'status' => $summary->getStatus(),
                'provider' => $summary->getProvider(),
                'model' => $summary->getModel(),
                'summaryJson' => $summary->getSummaryJson(),
                'aiInsights' => $this->callInsights->buildForSummary($summary),
            ])
            ->touch($summary->getUpdatedAt());
    }

    private function findOrCreate(
        string $sourceKey,
        \App\Entity\Tenant $tenant,
        string $type,
        \DateTimeImmutable $occurredAt,
    ): CommunicationTimelineItem {
        $item = $this->timeline->findOneBySourceKey($sourceKey);
        if ($item instanceof CommunicationTimelineItem) {
            return $item;
        }

        $item = (new CommunicationTimelineItem($tenant, $type, $occurredAt))
            ->setSourceKey($sourceKey);
        $this->entityManager->persist($item);

        return $item;
    }

    private function callDirection(CallSession $session): string
    {
        return match ($session->getFlowType()) {
            CallSession::FLOW_TYPE_INBOUND_FORWARD => 'inbound',
            CallSession::FLOW_TYPE_CLICK_TO_CALL => 'outbound',
            default => null !== $session->getInboundFrom() ? 'inbound' : 'unknown',
        };
    }

    private function callDurationSeconds(CallSession $session): ?int
    {
        if (null === $session->getStartedAt() || null === $session->getEndedAt()) {
            return null;
        }

        return max(0, $session->getEndedAt()->getTimestamp() - $session->getStartedAt()->getTimestamp());
    }

    private function callBodyText(CallSession $session): string
    {
        $contact = $session->getContact()?->getDisplayName();

        return match ($this->callDirection($session)) {
            'inbound' => sprintf('Inbound call%s.', null !== $contact ? ' with '.$contact : ''),
            'outbound' => sprintf('Outbound call%s.', null !== $contact ? ' to '.$contact : ''),
            default => sprintf('Call activity%s.', null !== $contact ? ' with '.$contact : ''),
        };
    }
}
