<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\CustomerSentimentHistory;
use App\Entity\Equipment;
use App\Entity\EquipmentServiceRecord;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\Job;
use App\Entity\MaintenancePlan;
use App\Entity\Property;
use App\Entity\PropertyMaintenancePlan;
use App\Entity\Quote;
use App\Entity\Rfq;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\RfqRepository;
use App\Service\PropertyLifecycleTimelineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PropertyLifecycleTimelineServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateDatabase();
    }

    public function testBuildForPropertyAggregatesFiltersAndSearchesLifecycleItems(): void
    {
        $data = $this->seedData();
        $service = static::getContainer()->get(PropertyLifecycleTimelineService::class);
        $rfqs = static::getContainer()->get(RfqRepository::class)->findByPropertyAddress($data['property']);
        self::assertCount(1, $rfqs, 'RFQ lookup returned '.count($rfqs).' item(s).');

        $allItems = $service->buildForProperty($data['property']);
        self::assertCount(11, $allItems['items'], 'Returned types: '.implode(', ', array_map(static fn (array $item): string => $item['type'], $allItems['items'])));
        self::assertSame('sentiment', $allItems['items'][0]['type']);
        self::assertSame('All types', $allItems['typeOptions']['all']);

        $jobItems = $service->buildForProperty($data['property'], 'job');
        self::assertCount(1, $jobItems['items']);
        self::assertSame('job', $jobItems['items'][0]['type']);
        self::assertStringContainsString('Furnace repair visit', $jobItems['items'][0]['title']);

        $searchItems = $service->buildForProperty($data['property'], 'all', 'furnace replacement');
        self::assertCount(1, $searchItems['items']);
        self::assertSame('rfq', $searchItems['items'][0]['type']);
        self::assertStringContainsString('Furnace replacement', $searchItems['items'][0]['searchText']);
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
     * @return array{tenant: Tenant, property: Property}
     */
    private function seedData(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = (new Tenant('Tenant One '.$suffix))->setEmail(sprintf('tenant-%s@example.com', $suffix));
        $user = (new User())->setEmail(sprintf('csr-%s@example.com', $suffix))->setPassword('unused')->setRoles(['ROLE_USER']);
        $property = new Property($tenant, '100 Main St '.$suffix, 'Toronto', 'ON', 'M1M1M1');
        $contact = new Contact($tenant, 'Primary Contact');

        $rfq = (new Rfq('100 Main St '.$suffix, 'Toronto', 'ON', 'M1M1M1'))
            ->setCustomerName('Rfq Customer')
            ->setProjectType('Furnace replacement');
        $rfq->setStatus(Rfq::STATUS_SUBMITTED);
        $this->setDateValue($rfq, 'createdAt', new \DateTimeImmutable('2026-06-01 09:00:00'));
        $this->setDateValue($rfq, 'updatedAt', new \DateTimeImmutable('2026-06-01 09:00:00'));

        $estimate = (new Estimate($tenant, $property))
            ->setTitle('Estimate for duct repair')
            ->setStatus(Estimate::STATUS_IN_REVIEW)
            ->setContact($contact);
        $this->setDateValue($estimate, 'updatedAt', new \DateTimeImmutable('2026-06-02 09:00:00'));

        $quote = (new Quote($tenant, $property, 'Q-1001-'.$suffix))
            ->setStatus(Quote::STATUS_SENT)
            ->setContact($contact)
            ->setSentAt(new \DateTimeImmutable('2026-06-03 09:00:00'));

        $equipment = (new Equipment($tenant, $property, Equipment::TYPE_FURNACE))
            ->setBrand('Acme')
            ->setModelNumber('X100')
            ->setInstalledAt(new \DateTimeImmutable('2026-06-05'));
        $this->setDateValue($equipment, 'updatedAt', new \DateTimeImmutable('2026-06-05 09:00:00'));

        $job = (new Job($tenant, $property))
            ->setTitle('Furnace repair visit')
            ->setEquipment($equipment)
            ->setStatus(Job::STATUS_COMPLETED)
            ->setCompletedAt(new \DateTimeImmutable('2026-06-04 09:00:00'));
        $this->setDateValue($job, 'updatedAt', new \DateTimeImmutable('2026-06-04 09:00:00'));

        $serviceRecord = (new EquipmentServiceRecord($tenant, $property))
            ->setEquipment($equipment)
            ->setTechnician($user)
            ->setServiceType('Annual tune-up')
            ->setCompletedAt(new \DateTimeImmutable('2026-06-06 09:00:00'));
        $this->setDateValue($serviceRecord, 'createdAt', new \DateTimeImmutable('2026-06-06 09:00:00'));
        $this->setDateValue($serviceRecord, 'updatedAt', new \DateTimeImmutable('2026-06-06 09:00:00'));

        $invoice = (new Invoice($tenant, $property, 'INV-1001-'.$suffix))
            ->setStatus(Invoice::STATUS_SENT)
            ->setSentAt(new \DateTimeImmutable('2026-06-07 09:00:00'));
        $this->setDateValue($invoice, 'updatedAt', new \DateTimeImmutable('2026-06-07 09:00:00'));

        $callSession = (new CallSession('provider-session-'.$suffix))
            ->setTenant($tenant)
            ->setProperty($property)
            ->setContact($contact)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_COMPLETED)
            ->setStartedAt(new \DateTimeImmutable('2026-06-08 08:00:00'))
            ->setEndedAt(new \DateTimeImmutable('2026-06-08 08:30:00'));
        $this->setDateValue($callSession, 'createdAt', new \DateTimeImmutable('2026-06-08 08:00:00'));
        $this->setDateValue($callSession, 'updatedAt', new \DateTimeImmutable('2026-06-08 08:30:00'));

        $maintenancePlan = new MaintenancePlan($tenant, 'Gold Protection');
        $maintenancePlan->setPlanType(MaintenancePlan::PLAN_GOLD);
        $assignment = (new PropertyMaintenancePlan($tenant, $property, $maintenancePlan))
            ->cancel(new \DateTimeImmutable('2026-06-09 09:00:00'));
        $this->setDateValue($assignment, 'createdAt', new \DateTimeImmutable('2026-06-02 09:00:00'));
        $this->setDateValue($assignment, 'updatedAt', new \DateTimeImmutable('2026-06-09 09:00:00'));

        $retentionOpportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_OPEN_INVOICE,
            'invoice:1001:'.$suffix,
            'Outstanding invoice balance.',
            $contact,
            null,
            new \DateTimeImmutable('2026-06-10 09:00:00'),
        );

        $sentiment = new CustomerSentimentHistory(
            $tenant,
            $property,
            $user,
            CustomerSentimentHistory::SENTIMENT_POSITIVE,
            'Customer happy with service.',
            new \DateTimeImmutable('2026-06-11 09:00:00'),
        );
        $sentiment->setContact($contact);
        $sentiment->setCallSession($callSession);

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist((new UserTenantMembership($user, $tenant))->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])->setIsDefault(true));
        $this->entityManager->persist($property);
        $this->entityManager->persist($contact);
        $this->entityManager->persist($rfq);
        $this->entityManager->persist($estimate);
        $this->entityManager->persist($quote);
        $this->entityManager->persist($equipment);
        $this->entityManager->persist($job);
        $this->entityManager->persist($serviceRecord);
        $this->entityManager->persist($invoice);
        $this->entityManager->persist($callSession);
        $this->entityManager->persist($maintenancePlan);
        $this->entityManager->persist($assignment);
        $this->entityManager->persist($retentionOpportunity);
        $this->entityManager->persist($sentiment);
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'property' => $property,
        ];
    }

    private function setDateValue(object $entity, string $property, \DateTimeImmutable $value): void
    {
        $reflectionProperty = new \ReflectionProperty($entity, $property);
        $reflectionProperty->setValue($entity, $value);
    }
}
