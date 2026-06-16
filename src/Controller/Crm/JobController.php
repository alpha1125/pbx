<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Job;
use App\Entity\EquipmentServiceRecord;
use App\Entity\User;
use App\Entity\Task;
use App\Entity\UserTenantMembership;
use App\Repository\JobRepository;
use App\Repository\EquipmentRepository;
use App\Repository\EquipmentServiceRecordRepository;
use App\Repository\TaskRepository;
use App\Repository\UserTenantMembershipRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\CommunicationTimelineProjector;
use App\Service\JobFollowUpService;
use App\Service\CurrentTenantProviderInterface;
use App\Service\TenantMembershipAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class JobController extends AbstractController
{
    #[Route('/crm/jobs', name: 'crm_job_index', methods: ['GET'])]
    public function index(
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $access,
        JobRepository $jobRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $access->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_TECHNICIAN,
        ]);

        return $this->render('crm/job/index.html.twig', [
            'tenant' => $tenant,
            'jobs' => $jobRepository->findByTenant($tenant),
        ]);
    }

    #[Route('/crm/jobs/queue', name: 'crm_job_queue', methods: ['GET'])]
    public function queue(
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $access,
        JobRepository $jobRepository,
        TaskRepository $taskRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $membership = $access->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_TECHNICIAN,
        ]);

        $currentUser = $membership->getUser();
        $jobs = $jobRepository->findAssignedToUser($tenant, $currentUser);
        $tasks = $taskRepository->findAssignedToUser($tenant, $currentUser);

        return $this->render('crm/job/queue.html.twig', [
            'tenant' => $tenant,
            'jobs' => $jobs,
            'tasks' => $tasks,
        ]);
    }

    #[Route('/crm/jobs/{id<\d+>}', name: 'crm_job_show', methods: ['GET'])]
    public function show(
        int $id,
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $access,
        JobRepository $jobRepository,
        TaskRepository $taskRepository,
        EquipmentRepository $equipmentRepository,
        EquipmentServiceRecordRepository $serviceRecordRepository,
        UserTenantMembershipRepository $membershipRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $access->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_TECHNICIAN,
        ]);

        $job = $jobRepository->findOneByTenantAndId($tenant, $id);
        if (null === $job) {
            throw $this->createNotFoundException('Job not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $job);

        return $this->render('crm/job/show.html.twig', [
            'tenant' => $tenant,
            'job' => $job,
            'tasks' => $taskRepository->findByJob($job),
            'followUpTasks' => $taskRepository->findFollowUpsByJob($job),
            'equipment' => $equipmentRepository->findByProperty($job->getProperty()),
            'serviceRecords' => $serviceRecordRepository->findByJob($job),
            'assigneeMemberships' => $membershipRepository->findByTenantOrdered($tenant, 1, 500),
        ]);
    }

    #[Route('/crm/jobs/{id<\d+>}/assignment', name: 'crm_job_assign', methods: ['POST'])]
    public function assign(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $access,
        JobRepository $jobRepository,
        UserTenantMembershipRepository $membershipRepository,
        CommunicationTimelineProjector $timelineProjector,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $access->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
        ]);

        $job = $jobRepository->findOneByTenantAndId($tenant, $id);
        if (null === $job) {
            throw $this->createNotFoundException('Job not found.');
        }

        if (!$this->isCsrfTokenValid('crm_job_assign_'.$job->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->applyAssignmentToJob($job, $request, $tenant, $membershipRepository);
        $entityManager->flush();
        $timelineProjector->recordJobEvent(
            $job,
            'job.assigned',
            sprintf(
                'Job assigned to %s%s.',
                $job->getAssignedTo()?->getDisplayName() ?? 'unassigned',
                null !== $job->getScheduledStartAt() ? ' for '.$job->getScheduledStartAt()->format('Y-m-d H:i') : '',
            ),
            ['propertyId' => $job->getProperty()->getId()],
        );
        $this->addFlash('success', 'Job assignment updated.');

        return $this->redirectToRoute('crm_job_show', ['id' => $job->getId()]);
    }

    #[Route('/crm/jobs/{jobId<\d+>}/tasks/{taskId<\d+>}/assignment', name: 'crm_job_task_assign', methods: ['POST'])]
    public function assignTask(
        int $jobId,
        int $taskId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $access,
        JobRepository $jobRepository,
        TaskRepository $taskRepository,
        UserTenantMembershipRepository $membershipRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $access->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
        ]);

        $job = $jobRepository->findOneByTenantAndId($tenant, $jobId);
        if (null === $job) {
            throw $this->createNotFoundException('Job not found.');
        }

        $task = $taskRepository->findOneByTenantAndId($tenant, $taskId);
        if (null === $task || $task->getJob()->getId() !== $job->getId()) {
            throw $this->createNotFoundException('Task not found.');
        }

        if (!$this->isCsrfTokenValid('crm_task_assign_'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->applyAssignmentToTask($task, $request, $tenant, $membershipRepository);
        $entityManager->flush();
        $this->addFlash('success', 'Task assignment updated.');

        return $this->redirectToRoute('crm_job_show', ['id' => $job->getId()]);
    }

    #[Route('/crm/jobs/{id<\d+>}/field-notes', name: 'crm_job_field_notes', methods: ['POST'])]
    public function fieldNotes(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $access,
        JobRepository $jobRepository,
        EquipmentRepository $equipmentRepository,
        EquipmentServiceRecordRepository $serviceRecordRepository,
        JobFollowUpService $followUpService,
        CommunicationTimelineProjector $timelineProjector,
        UserTenantMembershipRepository $membershipRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $access->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_TECHNICIAN,
        ]);

        $job = $jobRepository->findOneByTenantAndId($tenant, $id);
        if (null === $job) {
            throw $this->createNotFoundException('Job not found.');
        }

        if (!$this->isCsrfTokenValid('crm_job_field_notes_'.$job->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $arrivedAt = $this->dateTimeOrNull($request->request->get('arrivedAt'));
        $completedAt = $this->dateTimeOrNull($request->request->get('completedAt'));
        $technicianNotes = $this->nullableText($request->request->get('technicianNotes'));
        $recommendedRepairNotes = $this->nullableText($request->request->get('recommendedRepairNotes'));
        $recommendedReplacementNotes = $this->nullableText($request->request->get('recommendedReplacementNotes'));
        $unresolvedIssueNotes = $this->nullableText($request->request->get('unresolvedIssueNotes'));
        $serviceReminderAt = $this->dateTimeOrNull($request->request->get('serviceReminderAt'));
        $serviceReminderNotes = $this->nullableText($request->request->get('serviceReminderNotes'));
        $equipment = $this->equipmentFromRequest($equipmentRepository, $job, $request->request->get('equipmentId'));
        $technician = $this->currentUser($this->getUser());

        $job
            ->setArrivedAt($arrivedAt)
            ->setStartedAt($arrivedAt)
            ->setCompletedAt($completedAt)
            ->setTechnicianNotes($technicianNotes)
            ->setRecommendedRepairNotes($recommendedRepairNotes)
            ->setRecommendedReplacementNotes($recommendedReplacementNotes)
            ->setUnresolvedIssueNotes($unresolvedIssueNotes)
            ->setServiceReminderAt($serviceReminderAt)
            ->setServiceReminderNotes($serviceReminderNotes)
            ->setNotes($technicianNotes)
            ->setStatus(null !== $completedAt ? Job::STATUS_COMPLETED : Job::STATUS_IN_PROGRESS)
            ->touch();

        $record = (new EquipmentServiceRecord($tenant, $job->getProperty()))
            ->setEquipment($equipment)
            ->setJob($job)
            ->setTechnician($technician)
            ->setArrivedAt($arrivedAt)
            ->setCompletedAt($completedAt)
            ->setTechnicianNotes($technicianNotes)
            ->setRecommendedRepairNotes($recommendedRepairNotes)
            ->setRecommendedReplacementNotes($recommendedReplacementNotes)
            ->setServiceType($job->getTitle() ?? 'Field service visit');
        $entityManager->persist($record);
        $entityManager->flush();
        $timelineProjector->recordJobEvent(
            $job,
            null !== $completedAt ? 'job.completed' : 'job.field_notes_updated',
            null !== $completedAt
                ? sprintf(
                    'Job assigned to %s. Job marked completed with technician notes%s.',
                    $job->getAssignedTo()?->getDisplayName() ?? 'unassigned',
                    null !== $unresolvedIssueNotes ? ' and unresolved issues noted' : '',
                )
                : sprintf(
                    'Field notes updated for job%s.',
                    null !== $job->getAssignedTo() ? sprintf(' assigned to %s', $job->getAssignedTo()->getDisplayName() ?? 'unassigned') : '',
                ),
            ['propertyId' => $job->getProperty()->getId()],
        );
        $followUpTasks = $followUpService->generateForCompletedJob($job);

        $message = 'Field notes saved and service history updated.';
        if ([] !== $followUpTasks) {
            $message .= sprintf(' Follow-up workflow processed (%d task(s)).', count($followUpTasks));
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('crm_job_show', ['id' => $job->getId()]);
    }

    private function applyAssignmentToJob(
        Job $job,
        Request $request,
        \App\Entity\Tenant $tenant,
        UserTenantMembershipRepository $membershipRepository,
    ): void {
        $assignee = $this->assigneeFromRequest($tenant, $membershipRepository, $request->request->get('assignedToId'));
        $job
            ->setAssignedTo($assignee)
            ->setAssignedAt(null !== $assignee ? new \DateTimeImmutable() : null)
            ->setScheduledStartAt($this->dateTimeOrNull($request->request->get('scheduledStartAt')))
            ->setScheduledEndAt($this->dateTimeOrNull($request->request->get('scheduledEndAt')))
            ->setStatus((string) ($request->request->get('status') ?: Job::STATUS_UNSCHEDULED));
    }

    private function applyAssignmentToTask(
        Task $task,
        Request $request,
        \App\Entity\Tenant $tenant,
        UserTenantMembershipRepository $membershipRepository,
    ): void {
        $assignee = $this->assigneeFromRequest($tenant, $membershipRepository, $request->request->get('assignedToId'));
        $task
            ->setAssignedTo($assignee)
            ->setAssignedAt(null !== $assignee ? new \DateTimeImmutable() : null)
            ->setScheduledStartAt($this->dateTimeOrNull($request->request->get('scheduledStartAt')))
            ->setScheduledEndAt($this->dateTimeOrNull($request->request->get('scheduledEndAt')))
            ->setStatus((string) ($request->request->get('status') ?: Task::STATUS_UNSCHEDULED));
    }

    private function assigneeFromRequest(
        \App\Entity\Tenant $tenant,
        UserTenantMembershipRepository $membershipRepository,
        mixed $value,
    ): ?\App\Entity\User {
        $userId = is_scalar($value) ? (int) $value : 0;
        if ($userId <= 0) {
            return null;
        }

        foreach ($membershipRepository->findByTenantOrdered($tenant, 1, 500) as $membership) {
            if ($membership->getUser()->getId() === $userId) {
                return $membership->getUser();
            }
        }

        return null;
    }

    private function equipmentFromRequest(
        EquipmentRepository $equipmentRepository,
        Job $job,
        mixed $value,
    ): ?\App\Entity\Equipment {
        $equipmentId = is_scalar($value) ? (int) $value : 0;
        if ($equipmentId > 0) {
            $equipment = $equipmentRepository->findOneByTenantPropertyAndId($equipmentId, $job->getProperty());
            if (null !== $equipment) {
                return $equipment;
            }
        }

        return $job->getEquipment();
    }

    private function currentUser(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }

    private function nullableText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function dateTimeOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        return new \DateTimeImmutable(str_replace('T', ' ', trim($value)));
    }
}
