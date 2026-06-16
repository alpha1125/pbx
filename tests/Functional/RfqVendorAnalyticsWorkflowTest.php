<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Contact;
use App\Entity\Estimate;
use App\Entity\Job;
use App\Entity\Property;
use App\Entity\Quote;
use App\Entity\Rfq;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RfqVendorAnalyticsWorkflowTest extends WebTestCase
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

    public function testVendorAnalyticsDashboardShowsMetricsAndSearchFiltering(): void
    {
        $data = $this->seedData();

        $this->client->loginUser($data['adminUser']);

        $crawler = $this->client->request('GET', '/crm/rfq-analytics');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Vendor Analytics');
        self::assertSelectorTextContains('body', 'Beta Vendor');
        self::assertSelectorTextContains('body', 'Open rate');
        self::assertSelectorTextContains('body', 'Accept rate');
        self::assertSelectorTextContains('body', 'Quote rate');
        self::assertSelectorTextContains('body', 'Jobs completed');

        $this->client->request('GET', '/crm/rfq-analytics', ['q' => 'Beta']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Beta Vendor');

        $this->entityManager->clear();
        $tenant = static::getContainer()->get(TenantRepository::class)->find($data['vendorTenantA']->getId());
        self::assertInstanceOf(Tenant::class, $tenant);
        self::assertTrue($tenant->isRfqVendorEnabled());
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
     * @return array{
     *   vendorTenantA: Tenant,
     *   vendorTenantB: Tenant,
     *   adminUser: User
     * }
     */
    private function seedData(): array
    {
        $vendorTenantA = new Tenant('Alpha Vendor HVAC');
        $vendorTenantA->setRfqVendorEnabled(true)->setEmail('alpha@example.com');
        $vendorTenantB = new Tenant('Beta Vendor HVAC');
        $vendorTenantB->setRfqVendorEnabled(true)->setEmail('beta@example.com');
        $adminUser = (new User())->setEmail('admin@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);
        $this->entityManager->persist($vendorTenantA);
        $this->entityManager->persist($vendorTenantB);
        $this->entityManager->persist($adminUser);
        $this->entityManager->persist(
            (new UserTenantMembership($adminUser, $vendorTenantA))
                ->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])
                ->setIsDefault(true),
        );

        $propertyA = new Property($vendorTenantA, '10 Alpha St', 'Toronto', 'ON', 'M1M1M1');
        $propertyB = new Property($vendorTenantB, '20 Beta St', 'Toronto', 'ON', 'M2M2M2');
        $contactA = (new Contact($vendorTenantA, 'Alpha Contact'))->setPrimaryEmail('alpha-contact@example.com');
        $contactB = (new Contact($vendorTenantB, 'Beta Contact'))->setPrimaryEmail('beta-contact@example.com');
        $this->entityManager->persist($propertyA);
        $this->entityManager->persist($propertyB);
        $this->entityManager->persist($contactA);
        $this->entityManager->persist($contactB);

        $rfqA1 = new Rfq('100 Alpha St', 'Toronto', 'ON', 'M1M1M9');
        $rfqA2 = new Rfq('101 Alpha St', 'Toronto', 'ON', 'M1M1M8');
        $rfqB1 = new Rfq('200 Beta St', 'Toronto', 'ON', 'M2M2M9');
        $this->entityManager->persist($rfqA1);
        $this->entityManager->persist($rfqA2);
        $this->entityManager->persist($rfqB1);

        $invitationA1 = (new RfqInvitation($vendorTenantA, $rfqA1))
            ->setInvitedAt(new \DateTimeImmutable('2026-06-16 10:00:00'))
            ->setViewedAt(new \DateTimeImmutable('2026-06-16 10:30:00'))
            ->setAcceptedAt(new \DateTimeImmutable('2026-06-16 10:45:00'))
            ->setStatus(RfqInvitation::STATUS_ACCEPTED_FOR_QUOTE);
        $estimateA1 = (new Estimate($vendorTenantA, $propertyA))
            ->setContact($contactA)
            ->setRfqInvitation($invitationA1)
            ->setStatus(Estimate::STATUS_DRAFT)
            ->setTitle('Alpha estimate');
        $invitationA1->setCreatedEstimate($estimateA1);
        $quoteA1 = (new Quote($vendorTenantA, $propertyA, 'Q-ALPHA-1'))
            ->setContact($contactA)
            ->setEstimate($estimateA1)
            ->setStatus(Quote::STATUS_ACCEPTED)
            ->setSentAt(new \DateTimeImmutable('2026-06-16 11:30:00'))
            ->setViewedAt(new \DateTimeImmutable('2026-06-16 11:45:00'))
            ->setAcceptedAt(new \DateTimeImmutable('2026-06-16 12:05:00'));
        $jobA1 = (new Job($vendorTenantA, $propertyA))
            ->setQuote($quoteA1)
            ->setStartedAt(new \DateTimeImmutable('2026-06-16 14:00:00'))
            ->setCompletedAt(new \DateTimeImmutable('2026-06-16 15:30:00'))
            ->setStatus(Job::STATUS_COMPLETED);

        $invitationA2 = (new RfqInvitation($vendorTenantA, $rfqA2))
            ->setInvitedAt(new \DateTimeImmutable('2026-06-16 11:00:00'))
            ->setDeclinedAt(new \DateTimeImmutable('2026-06-16 12:00:00'))
            ->setStatus(RfqInvitation::STATUS_DECLINED);

        $invitationB1 = (new RfqInvitation($vendorTenantB, $rfqB1))
            ->setInvitedAt(new \DateTimeImmutable('2026-06-16 13:00:00'))
            ->setViewedAt(new \DateTimeImmutable('2026-06-16 13:15:00'))
            ->setStatus(RfqInvitation::STATUS_VIEWED);

        $this->entityManager->persist($invitationA1);
        $this->entityManager->persist($estimateA1);
        $this->entityManager->persist($quoteA1);
        $this->entityManager->persist($jobA1);
        $this->entityManager->persist($invitationA2);
        $this->entityManager->persist($invitationB1);
        $this->entityManager->flush();

        return [
            'vendorTenantA' => $vendorTenantA,
            'vendorTenantB' => $vendorTenantB,
            'adminUser' => $adminUser,
        ];
    }
}
