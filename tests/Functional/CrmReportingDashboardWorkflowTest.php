<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use App\Entity\Property;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CrmReportingDashboardWorkflowTest extends WebTestCase
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

    public function testReportingDashboardShowsRevenueOpportunityCards(): void
    {
        $data = $this->seedData();
        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);

        $this->client->request('GET', '/crm/reporting');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Revenue Opportunity Dashboard');
        self::assertSelectorTextContains('#revenue-opportunity-dashboard', 'Dormant Customers');
        self::assertSelectorTextContains('#revenue-opportunity-dashboard', 'Maintenance Opportunities');
        self::assertSelectorTextContains('#revenue-opportunity-dashboard', 'Replacement Opportunities');
        self::assertSelectorTextContains('#revenue-opportunity-dashboard', 'Warranty Opportunities');
        self::assertSelectorTextContains('#revenue-opportunity-dashboard', 'Overdue Invoice Opportunities');
        self::assertSelectorTextContains('#revenue-opportunity-dashboard', '123 Revenue St');
        self::assertSelectorTextContains('#revenue-opportunity-dashboard', '$1,050');
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
     * @return array{tenant: Tenant, user: User}
     */
    private function seedData(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = (new Tenant('Reporting Tenant '.$suffix))->setEmail(sprintf('reporting-%s@example.com', $suffix));
        $user = (new User())->setEmail(sprintf('reporting-user-%s@example.com', $suffix))->setPassword('unused')->setRoles(['ROLE_USER']);
        $adminMembership = (new UserTenantMembership($user, $tenant))
            ->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])
            ->setIsDefault(true);

        $dormantProperty = new Property($tenant, '101 Dormant St', 'Toronto', 'ON', 'M1M1M1');
        $maintenanceProperty = new Property($tenant, '102 Maintenance Rd', 'Toronto', 'ON', 'M1M1M2');
        $replacementProperty = new Property($tenant, '103 Replacement Ave', 'Toronto', 'ON', 'M1M1M3');
        $warrantyProperty = new Property($tenant, '104 Warranty Blvd', 'Toronto', 'ON', 'M1M1M4');
        $invoiceProperty = new Property($tenant, '123 Revenue St', 'Toronto', 'ON', 'M1M1M5');

        $dormantOpportunity = new RetentionOpportunity(
            $tenant,
            $dormantProperty,
            RetentionOpportunity::TYPE_DORMANT_CUSTOMER,
            'dormant-'.$suffix,
            'Customer has been inactive for a while.',
        );
        $maintenanceServiceOpportunity = new RetentionOpportunity(
            $tenant,
            $maintenanceProperty,
            RetentionOpportunity::TYPE_NO_RECENT_SERVICE,
            'maintenance-service-'.$suffix,
            'No recent service is recorded.',
        );
        $maintenanceCallOpportunity = new RetentionOpportunity(
            $tenant,
            $maintenanceProperty,
            RetentionOpportunity::TYPE_NO_RECENT_CALLS,
            'maintenance-calls-'.$suffix,
            'No recent calls are recorded.',
        );
        $maintenancePlanOpportunity = new RetentionOpportunity(
            $tenant,
            $maintenanceProperty,
            RetentionOpportunity::TYPE_MAINTENANCE_PLAN_MISSING,
            'maintenance-plan-'.$suffix,
            'No maintenance plan is assigned.',
        );
        $replacementOpportunity = new RetentionOpportunity(
            $tenant,
            $replacementProperty,
            RetentionOpportunity::TYPE_OLD_EQUIPMENT,
            'replacement-'.$suffix,
            'Equipment is old enough to review replacement options.',
        );
        $warrantyOpportunity = new RetentionOpportunity(
            $tenant,
            $warrantyProperty,
            RetentionOpportunity::TYPE_WARRANTY_NEARING_EXPIRATION,
            'warranty-'.$suffix,
            'Warranty is nearing expiration.',
        );
        $invoiceOpportunity = new RetentionOpportunity(
            $tenant,
            $invoiceProperty,
            RetentionOpportunity::TYPE_OPEN_INVOICE,
            'invoice-'.$suffix,
            'Outstanding invoice balance.',
        );
        $invoice = (new Invoice($tenant, $invoiceProperty, 'INV-'.$suffix))
            ->setStatus(Invoice::STATUS_SENT)
            ->setTotalCents(120000)
            ->setAmountPaidCents(15000)
            ->setSentAt(new \DateTimeImmutable('2026-06-01 09:00:00'));

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($adminMembership);
        $this->entityManager->persist($dormantProperty);
        $this->entityManager->persist($maintenanceProperty);
        $this->entityManager->persist($replacementProperty);
        $this->entityManager->persist($warrantyProperty);
        $this->entityManager->persist($invoiceProperty);
        $this->entityManager->persist($dormantOpportunity);
        $this->entityManager->persist($maintenanceServiceOpportunity);
        $this->entityManager->persist($maintenanceCallOpportunity);
        $this->entityManager->persist($maintenancePlanOpportunity);
        $this->entityManager->persist($replacementOpportunity);
        $this->entityManager->persist($warrantyOpportunity);
        $this->entityManager->persist($invoiceOpportunity);
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'user' => $user,
        ];
    }
}
