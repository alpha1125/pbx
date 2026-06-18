<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallSession;
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
use App\Service\CustomerJourneyDashboardService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CustomerJourneyDashboardServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateDatabase();
    }

    public function testBuildForPropertyReturnsSequentialJourneyStagesWithLinks(): void
    {
        $data = $this->seedData();
        $service = static::getContainer()->get(CustomerJourneyDashboardService::class);

        $journey = $service->buildForProperty($data['property']);

        self::assertCount(9, $journey['stages']);
        self::assertSame('replacement', $journey['currentStage']);
        self::assertSame('Replacement', $journey['currentLabel']);
        self::assertSame(8, $journey['completedCount']);
        self::assertSame(0, $journey['upcomingCount']);

        self::assertSame('complete', $journey['stages'][0]['status']);
        self::assertSame('crm_rfq_show', $journey['stages'][0]['link']['route']);
        self::assertSame('current', $journey['stages'][8]['status']);
        self::assertSame('retention-opportunities-card', $journey['stages'][8]['link']['fragment']);
        self::assertSame('assigned-maintenance-plans-card', $journey['stages'][6]['link']['fragment']);
        self::assertSame('crm_job_show', $journey['stages'][3]['link']['route']);
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
        $tenant = (new Tenant('Journey Tenant '.$suffix))->setEmail(sprintf('journey-%s@example.com', $suffix));
        $property = new Property($tenant, '500 Journey St '.$suffix, 'Toronto', 'ON', 'M1M1M1');
        $technician = (new \App\Entity\User())->setEmail(sprintf('journey-tech-%s@example.com', $suffix))->setPassword('unused')->setRoles(['ROLE_USER']);
        $contact = new \App\Entity\Contact($tenant, 'Primary Contact');

        $rfq = (new Rfq('500 Journey St '.$suffix, 'Toronto', 'ON', 'M1M1M1'))
            ->setCustomerName('Journey Customer')
            ->setProjectType('Furnace replacement');
        $this->setDateValue($rfq, 'createdAt', new \DateTimeImmutable('2026-06-01 09:00:00'));
        $this->setDateValue($rfq, 'updatedAt', new \DateTimeImmutable('2026-06-01 09:00:00'));

        $estimate = (new Estimate($tenant, $property))
            ->setContact($contact)
            ->setTitle('Journey estimate');
        $this->setDateValue($estimate, 'updatedAt', new \DateTimeImmutable('2026-06-02 09:00:00'));

        $quote = (new Quote($tenant, $property, 'Q-JOURNEY-'.$suffix))
            ->setContact($contact)
            ->setSentAt(new \DateTimeImmutable('2026-06-03 09:00:00'));

        $equipment = (new Equipment($tenant, $property, Equipment::TYPE_FURNACE))
            ->setBrand('Acme')
            ->setInstalledAt(new \DateTimeImmutable('2026-06-04'))
            ->setWarrantyExpiresAt(new \DateTimeImmutable('2027-06-04'));
        $this->setDateValue($equipment, 'updatedAt', new \DateTimeImmutable('2026-06-04 09:00:00'));

        $job = (new Job($tenant, $property))
            ->setTitle('Install furnace')
            ->setEquipment($equipment)
            ->setStatus(Job::STATUS_COMPLETED)
            ->setCompletedAt(new \DateTimeImmutable('2026-06-05 09:00:00'));
        $this->setDateValue($job, 'updatedAt', new \DateTimeImmutable('2026-06-05 09:00:00'));

        $serviceRecord = (new EquipmentServiceRecord($tenant, $property))
            ->setEquipment($equipment)
            ->setJob($job)
            ->setTechnician($technician)
            ->setServiceType('annual tune-up')
            ->setCompletedAt(new \DateTimeImmutable('2026-06-06 09:00:00'));
        $this->setDateValue($serviceRecord, 'createdAt', new \DateTimeImmutable('2026-06-06 09:00:00'));
        $this->setDateValue($serviceRecord, 'updatedAt', new \DateTimeImmutable('2026-06-06 09:00:00'));

        $invoice = (new Invoice($tenant, $property, 'INV-JOURNEY-'.$suffix))
            ->setStatus(Invoice::STATUS_SENT)
            ->setTotalCents(125000)
            ->setAmountPaidCents(25000)
            ->setSentAt(new \DateTimeImmutable('2026-06-07 09:00:00'));

        $maintenancePlan = (new MaintenancePlan($tenant, 'Gold Protection'))
            ->setPlanType(MaintenancePlan::PLAN_GOLD)
            ->setRenewalDate(new \DateTimeImmutable('2026-12-01'));
        $assignment = new PropertyMaintenancePlan($tenant, $property, $maintenancePlan);
        $this->setDateValue($assignment, 'createdAt', new \DateTimeImmutable('2026-06-08 09:00:00'));

        $retentionOpportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_OLD_EQUIPMENT,
            'replacement-'.$suffix,
            'Old equipment needs review.',
            $contact,
            $equipment,
            new \DateTimeImmutable('2026-06-09 09:00:00'),
        );

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($property);
        $this->entityManager->persist($technician);
        $this->entityManager->persist($contact);
        $this->entityManager->persist($rfq);
        $this->entityManager->persist($estimate);
        $this->entityManager->persist($quote);
        $this->entityManager->persist($equipment);
        $this->entityManager->persist($job);
        $this->entityManager->persist($serviceRecord);
        $this->entityManager->persist($invoice);
        $this->entityManager->persist($maintenancePlan);
        $this->entityManager->persist($assignment);
        $this->entityManager->persist($retentionOpportunity);
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
