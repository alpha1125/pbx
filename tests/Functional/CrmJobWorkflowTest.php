<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Contact;
use App\Entity\Equipment;
use App\Entity\Job;
use App\Entity\Property;
use App\Entity\Task;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\JobRepository;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CrmJobWorkflowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateDatabase();
        $this->client->disableReboot();
    }

    public function testDispatchBoardCanAssignJobsAndTasksAndTechnicianQueueShowsThem(): void
    {
        $data = $this->seedData();

        $this->client->loginUser($data['dispatchUser']);

        $crawler = $this->client->request('GET', '/crm/jobs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Dispatch Board');
        self::assertSelectorTextContains('body', 'Replace furnace');

        $crawler = $this->client->request('GET', '/crm/jobs/'.$data['job']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Scheduling');

        $jobAssignmentToken = $crawler->filter('form[action="/crm/jobs/'.$data['job']->getId().'/assignment"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/jobs/%d/assignment', $data['job']->getId()), [
            '_token' => $jobAssignmentToken,
            'assignedToId' => (string) $data['technicianUser']->getId(),
            'status' => Job::STATUS_SCHEDULED,
            'scheduledStartAt' => '2026-06-20T09:00',
            'scheduledEndAt' => '2026-06-20T11:00',
        ]);

        self::assertResponseRedirects('/crm/jobs/'.$data['job']->getId());

        $crawler = $this->client->request('GET', '/crm/jobs/'.$data['job']->getId());
        $taskAssignmentToken = $crawler->filter('form[action="/crm/jobs/'.$data['job']->getId().'/tasks/'.$data['task']->getId().'/assignment"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/jobs/%d/tasks/%d/assignment', $data['job']->getId(), $data['task']->getId()), [
            '_token' => $taskAssignmentToken,
            'assignedToId' => (string) $data['technicianUser']->getId(),
            'status' => Task::STATUS_SCHEDULED,
            'scheduledStartAt' => '2026-06-20T09:15',
            'scheduledEndAt' => '2026-06-20T09:45',
        ]);

        self::assertResponseRedirects('/crm/jobs/'.$data['job']->getId());

        $this->entityManager->clear();
        $job = static::getContainer()->get(JobRepository::class)->findOneByTenantAndId($data['tenant'], $data['job']->getId());
        $task = static::getContainer()->get(TaskRepository::class)->findOneByTenantAndId($data['tenant'], $data['task']->getId());
        self::assertInstanceOf(Job::class, $job);
        self::assertInstanceOf(Task::class, $task);
        self::assertSame(Job::STATUS_SCHEDULED, $job->getStatus());
        self::assertSame(Task::STATUS_SCHEDULED, $task->getStatus());
        self::assertSame($data['technicianUser']->getId(), $job->getAssignedTo()?->getId());
        self::assertSame($data['technicianUser']->getId(), $task->getAssignedTo()?->getId());
        self::assertNotNull($job->getAssignedAt());
        self::assertNotNull($task->getAssignedAt());

        $this->client->loginUser($data['technicianUser']);
        $this->client->request('GET', '/crm/jobs/queue');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Technician Queue');
        self::assertSelectorTextContains('body', 'Replace furnace');
        self::assertSelectorTextContains('body', 'Order parts');
    }

    public function testFieldNotesCreateServiceHistoryOnPropertyPage(): void
    {
        $data = $this->seedData();

        $this->client->loginUser($data['technicianUser']);
        $crawler = $this->client->request('GET', '/crm/jobs/'.$data['job']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Field Notes');

        $token = $crawler->filter('form[action="/crm/jobs/'.$data['job']->getId().'/field-notes"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/jobs/%d/field-notes', $data['job']->getId()), [
            '_token' => $token,
            'equipmentId' => (string) $data['equipment']->getId(),
            'arrivedAt' => '2026-06-20T09:05',
            'completedAt' => '2026-06-20T10:15',
            'technicianNotes' => 'Replaced failed inducer motor.',
            'recommendedRepairNotes' => 'Seal duct connections.',
            'recommendedReplacementNotes' => 'Consider replacing aging furnace next season.',
            'unresolvedIssueNotes' => 'Customer wants a follow-up estimate for replacement options.',
            'serviceReminderAt' => '2026-07-20T09:30',
            'serviceReminderNotes' => 'Schedule annual maintenance follow-up.',
        ]);

        self::assertResponseRedirects('/crm/jobs/'.$data['job']->getId());

        $this->entityManager->clear();
        $job = static::getContainer()->get(JobRepository::class)->findOneByTenantAndId($data['tenant'], $data['job']->getId());
        $followUpTasks = static::getContainer()->get(TaskRepository::class)->findFollowUpsByJob($job);
        self::assertInstanceOf(Job::class, $job);
        self::assertSame('Replaced failed inducer motor.', $job->getTechnicianNotes());
        self::assertSame(Job::STATUS_COMPLETED, $job->getStatus());
        self::assertNotNull($job->getArrivedAt());
        self::assertNotNull($job->getCompletedAt());
        self::assertNotNull($job->getFollowUpGeneratedAt());
        self::assertCount(4, $followUpTasks);
        self::assertSame('Generated 4 follow-up task(s).', $job->getFollowUpSummary());

        $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Service History');
        self::assertSelectorTextContains('body', 'Replaced failed inducer motor.');
        self::assertSelectorTextContains('body', 'Seal duct connections.');
        self::assertSelectorTextContains('body', 'Consider replacing aging furnace next season.');
        self::assertSelectorTextContains('body', 'Follow-up Tasks');
        self::assertSelectorTextContains('body', 'Repair follow-up');
        self::assertSelectorTextContains('body', 'Replacement follow-up');
        self::assertSelectorTextContains('body', 'Unresolved issue follow-up');
        self::assertSelectorTextContains('body', 'Service reminder');
        self::assertSelectorTextContains('body', 'Job assigned to');
        self::assertSelectorTextContains('body', 'Job marked completed with technician notes');
        self::assertSelectorTextContains('body', 'Furnace');
    }

    private function truncateDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        $platformClass = $connection->getDatabasePlatform()::class;
        if (!str_contains($platformClass, 'PostgreSQL')) {
            return;
        }

        $tables = array_filter(
            $connection->createSchemaManager()->listTableNames(),
            static fn (string $table): bool => 'doctrine_migration_versions' !== $table,
        );

        if ([] === $tables) {
            return;
        }

        $connection->executeStatement('TRUNCATE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
    }

    /**
     * @return array{tenant: Tenant, property: Property, dispatchUser: User, technicianUser: User, job: Job, task: Task, equipment: Equipment}
     */
    private function seedData(): array
    {
        $tenant = (new Tenant('Tenant One'))->setEmail('tenant@example.com');
        $property = new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1');
        $contact = (new Contact($tenant, 'Tenant Contact'))->setPrimaryPhone('+14165550123');
        $equipment = (new Equipment($tenant, $property, Equipment::TYPE_FURNACE))
            ->setBrand('Acme')
            ->setModelNumber('F-1000')
            ->setStatus(Equipment::STATUS_ACTIVE);
        $dispatchUser = (new User())->setEmail('dispatch@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);
        $technicianUser = (new User())->setEmail('tech@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($property);
        $this->entityManager->persist($contact);
        $this->entityManager->persist($equipment);
        $this->entityManager->persist($dispatchUser);
        $this->entityManager->persist($technicianUser);
        $this->entityManager->persist((new UserTenantMembership($dispatchUser, $tenant))->setRoles([UserTenantMembership::ROLE_DISPATCH])->setIsDefault(true));
        $this->entityManager->persist((new UserTenantMembership($technicianUser, $tenant))->setRoles([UserTenantMembership::ROLE_TECHNICIAN]));

        $job = (new Job($tenant, $property))
            ->setContact($contact)
            ->setEquipment($equipment)
            ->setTitle('Replace furnace');
        $task = (new Task($tenant, $job, 'Order parts'))
            ->setDescription('Prepare equipment and parts list.');

        $this->entityManager->persist($job);
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'property' => $property,
            'dispatchUser' => $dispatchUser,
            'technicianUser' => $technicianUser,
            'job' => $job,
            'task' => $task,
            'equipment' => $equipment,
        ];
    }
}
