<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Job;
use App\Entity\Task;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;

final class JobFollowUpService
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return list<Task>
     */
    public function generateForCompletedJob(Job $job): array
    {
        if (Job::STATUS_COMPLETED !== $job->getStatus() || null === $job->getCompletedAt()) {
            return [];
        }

        if (null !== $job->getFollowUpGeneratedAt()) {
            return $this->taskRepository->findFollowUpsByJob($job);
        }

        $tasks = [];
        $baseDueAt = $job->getCompletedAt();
        $followUpSpec = [];

        if (null !== $job->getServiceReminderAt()) {
            $followUpSpec[] = [
                'title' => 'Service reminder',
                'kind' => Task::KIND_SERVICE_REMINDER,
                'description' => $job->getServiceReminderNotes() ?? 'Follow up with the customer for scheduled service.',
                'notes' => 'Generated from an explicit service reminder request.',
                'dueAt' => $job->getServiceReminderAt(),
            ];
        }

        if (null !== $job->getRecommendedRepairNotes()) {
            $followUpSpec[] = [
                'title' => 'Repair follow-up',
                'kind' => Task::KIND_FOLLOW_UP,
                'description' => $job->getRecommendedRepairNotes(),
                'notes' => 'Generated from completed job repair recommendation.',
                'offset' => '+7 days',
            ];
        }

        if (null !== $job->getRecommendedReplacementNotes()) {
            $followUpSpec[] = [
                'title' => 'Replacement follow-up',
                'kind' => Task::KIND_FOLLOW_UP,
                'description' => $job->getRecommendedReplacementNotes(),
                'notes' => 'Generated from completed job replacement recommendation.',
                'offset' => '+14 days',
            ];
        }

        if (null !== $job->getUnresolvedIssueNotes()) {
            $followUpSpec[] = [
                'title' => 'Unresolved issue follow-up',
                'kind' => Task::KIND_FOLLOW_UP,
                'description' => $job->getUnresolvedIssueNotes(),
                'notes' => 'Generated from unresolved issues reported during the job.',
                'offset' => '+3 days',
            ];
        }

        if ([] === $followUpSpec) {
            $followUpSpec[] = [
                'title' => 'Service reminder',
                'kind' => Task::KIND_SERVICE_REMINDER,
                'description' => 'Follow up with the customer after the completed service visit.',
                'notes' => 'Generated as a default post-job service reminder.',
                'offset' => '+30 days',
            ];
        }

        foreach ($followUpSpec as $spec) {
            $dueAt = $spec['dueAt'] ?? $baseDueAt->modify($spec['offset']);
            $task = (new Task($job->getTenant(), $job, $spec['title']))
                ->setKind($spec['kind'])
                ->setStatus(Task::STATUS_SCHEDULED)
                ->setAssignedTo($job->getAssignedTo())
                ->setAssignedAt($job->getAssignedAt())
                ->setScheduledStartAt($dueAt)
                ->setScheduledEndAt($dueAt->modify('+1 hour'))
                ->setDescription($spec['description'])
                ->setNotes($spec['notes']);

            $this->entityManager->persist($task);
            $tasks[] = $task;
        }

        $job
            ->setFollowUpGeneratedAt(new \DateTimeImmutable())
            ->setFollowUpSummary(sprintf('Generated %d follow-up task(s).', count($tasks)))
            ->touch();

        $this->auditLogger->log(
            $job->getTenant(),
            'job',
            (string) $job->getId(),
            'job.follow_up_generated',
            null,
            [
                'jobId' => $job->getId(),
                'generatedCount' => count($tasks),
                'summary' => $job->getFollowUpSummary(),
            ],
            ['propertyId' => $job->getProperty()->getId()],
        );

        $this->entityManager->flush();

        return $tasks;
    }
}
