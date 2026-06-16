<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Job;
use App\Entity\Quote;
use App\Repository\JobRepository;
use Doctrine\ORM\EntityManagerInterface;

final class QuoteToJobService
{
    public function __construct(
        private readonly JobRepository $jobRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly CommunicationTimelineProjector $timelineProjector,
    ) {
    }

    public function createFromAcceptedQuote(Quote $quote): Job
    {
        if (Quote::STATUS_ACCEPTED !== $quote->getStatus()) {
            throw new \RuntimeException(sprintf('Quote %s must be accepted before creating a job.', $quote->getQuoteNumber()));
        }

        $existing = $this->jobRepository->findOneByQuote($quote);
        if ($existing instanceof Job) {
            return $existing;
        }

        $job = (new Job($quote->getTenant(), $quote->getProperty()))
            ->setQuote($quote)
            ->setContact($quote->getContact())
            ->setTitle(sprintf('Work order for quote %s', $quote->getQuoteNumber()));

        $this->entityManager->persist($job);

        $this->auditLogger->log(
            $quote->getTenant(),
            'job',
            'new',
            'job.created_from_quote',
            null,
            [
                'quoteNumber' => $quote->getQuoteNumber(),
                'jobTitle' => $job->getTitle(),
            ],
            ['quoteId' => $quote->getId(), 'propertyId' => $quote->getProperty()->getId()],
        );

        $this->entityManager->flush();
        $this->timelineProjector->recordJobEvent(
            $job,
            'job.created_from_quote',
            'Job created from accepted quote.',
            ['quoteId' => $quote->getId(), 'propertyId' => $quote->getProperty()->getId()],
        );

        return $job;
    }
}
