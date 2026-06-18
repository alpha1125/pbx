<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CallSession;
use App\Entity\Contact;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CrmCustomerJourneyWorkflowTest extends WebTestCase
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

    public function testCustomerJourneyDashboardRendersStageLinksOnPropertyPage(): void
    {
        $data = $this->seedData();
        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);

        $crawler = $this->client->request('GET', '/crm/properties/'.$data['property']->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#customer-journey-card');
        self::assertSelectorTextContains('#customer-journey-card', 'Customer Journey Dashboard');
        self::assertCount(9, $crawler->filter('#customer-journey-card [data-customer-journey-stage]'));
        self::assertSame('current', $crawler->filter('#customer-journey-card [data-customer-journey-stage="replacement"]')->attr('data-customer-journey-status'));
        self::assertSame('complete', $crawler->filter('#customer-journey-card [data-customer-journey-stage="rfq"]')->attr('data-customer-journey-status'));
        self::assertSame('/crm/rfqs/'.$data['rfq']->getId(), $crawler->filter('#customer-journey-card [data-customer-journey-stage="rfq"] a')->attr('href'));
        self::assertSame('/crm/properties/'.$data['property']->getId().'#assigned-maintenance-plans-card', $crawler->filter('#customer-journey-card [data-customer-journey-stage="maintenance"] a')->attr('href'));
        self::assertSame('/crm/properties/'.$data['property']->getId().'#retention-opportunities-card', $crawler->filter('#customer-journey-card [data-customer-journey-stage="replacement"] a')->attr('href'));
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

    private function selectTenant(Tenant $tenant): void
    {
        $this->client->request('GET', '/crm/no-tenant');
        $session = $this->client->getRequest()->getSession();
        $session->set('crm.current_tenant_id', $tenant->getId());
        $session->save();
    }

    /**
     * @return array{tenant: Tenant, user: User, property: Property, rfq: Rfq}
     */
    private function seedData(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = (new Tenant('Journey Tenant '.$suffix))->setEmail(sprintf('journey-%s@example.com', $suffix));
        $user = (new User())->setEmail(sprintf('journey-user-%s@example.com', $suffix))->setPassword('unused')->setRoles(['ROLE_USER']);
        $property = new Property($tenant, '500 Journey St '.$suffix, 'Toronto', 'ON', 'M1M1M1');
        $contact = new Contact($tenant, 'Primary Contact');
        $technician = (new User())->setEmail(sprintf('journey-tech-%s@example.com', $suffix))->setPassword('unused')->setRoles(['ROLE_USER']);

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
        $this->entityManager->persist($user);
        $this->entityManager->persist((new UserTenantMembership($user, $tenant))->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])->setIsDefault(true));
        $this->entityManager->persist($property);
        $this->entityManager->persist($contact);
        $this->entityManager->persist($technician);
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
            'user' => $user,
            'property' => $property,
            'rfq' => $rfq,
        ];
    }

    private function setDateValue(object $entity, string $property, \DateTimeImmutable $value): void
    {
        $reflectionProperty = new \ReflectionProperty($entity, $property);
        $reflectionProperty->setValue($entity, $value);
    }
}
