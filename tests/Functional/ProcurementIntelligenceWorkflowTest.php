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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProcurementIntelligenceWorkflowTest extends WebTestCase
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

    public function testProcurementIntelligenceDashboardShowsRecommendationsAndTrends(): void
    {
        $data = $this->seedData();

        $this->client->loginUser($data['adminUser']);

        $crawler = $this->client->request('GET', '/crm/procurement-intelligence');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Procurement Intelligence');
        self::assertSelectorTextContains('body', 'Trend Reporting');
        self::assertSelectorTextContains('body', 'Vendor Ranking Placeholders');
        self::assertSelectorTextContains('body', 'Prioritize Alpha Vendor HVAC');
        self::assertSelectorTextContains('body', 'Alpha Vendor HVAC');
        self::assertSelectorTextContains('body', 'Beta Vendor HVAC');

        $token = $crawler->filter('input[name="q"]')->attr('value');
        self::assertSame('', $token);

        $this->client->request('GET', '/crm/procurement-intelligence', ['q' => 'Beta']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Beta Vendor HVAC');
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
     *   adminUser: User
     * }
     */
    private function seedData(): array
    {
        $vendorTenantA = (new Tenant('Alpha Vendor HVAC'))->setRfqVendorEnabled(true)->setEmail('alpha@example.com');
        $vendorTenantB = (new Tenant('Beta Vendor HVAC'))->setRfqVendorEnabled(true)->setEmail('beta@example.com');
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

        $rfqA = (new Rfq('100 Alpha St', 'Toronto', 'ON', 'M1M1M9'))->setStatus(Rfq::STATUS_SENT_TO_VENDORS);
        $rfqB = (new Rfq('200 Beta St', 'Toronto', 'ON', 'M2M2M9'))->setStatus(Rfq::STATUS_SENT_TO_VENDORS);
        $rfqA->touch(new \DateTimeImmutable('2026-06-11 09:00:00'));
        $rfqB->touch(new \DateTimeImmutable('2026-06-12 09:00:00'));
        $this->entityManager->persist($rfqA);
        $this->entityManager->persist($rfqB);

        $invitationA = (new RfqInvitation($vendorTenantA, $rfqA))
            ->setInvitedAt(new \DateTimeImmutable('2026-06-11 10:00:00'))
            ->setViewedAt(new \DateTimeImmutable('2026-06-11 10:20:00'))
            ->setAcceptedAt(new \DateTimeImmutable('2026-06-11 10:35:00'))
            ->setStatus(RfqInvitation::STATUS_ACCEPTED_FOR_QUOTE);
        $estimateA = (new Estimate($vendorTenantA, $propertyA))
            ->setContact($contactA)
            ->setRfqInvitation($invitationA)
            ->setStatus(Estimate::STATUS_DRAFT)
            ->setTitle('Alpha estimate');
        $invitationA->setCreatedEstimate($estimateA);
        $quoteA = (new Quote($vendorTenantA, $propertyA, 'Q-ALPHA-1'))
            ->setContact($contactA)
            ->setEstimate($estimateA)
            ->setStatus(Quote::STATUS_ACCEPTED)
            ->setSentAt(new \DateTimeImmutable('2026-06-11 11:00:00'))
            ->setViewedAt(new \DateTimeImmutable('2026-06-11 11:15:00'))
            ->setAcceptedAt(new \DateTimeImmutable('2026-06-11 11:40:00'));
        $jobA = (new Job($vendorTenantA, $propertyA))
            ->setQuote($quoteA)
            ->setStartedAt(new \DateTimeImmutable('2026-06-12 08:00:00'))
            ->setCompletedAt(new \DateTimeImmutable('2026-06-12 11:00:00'))
            ->setStatus(Job::STATUS_COMPLETED);

        $invitationB = (new RfqInvitation($vendorTenantB, $rfqB))
            ->setInvitedAt(new \DateTimeImmutable('2026-06-12 10:00:00'))
            ->setViewedAt(new \DateTimeImmutable('2026-06-12 10:45:00'))
            ->setDeclinedAt(new \DateTimeImmutable('2026-06-12 11:30:00'))
            ->setStatus(RfqInvitation::STATUS_DECLINED);

        $this->entityManager->persist($invitationA);
        $this->entityManager->persist($estimateA);
        $this->entityManager->persist($quoteA);
        $this->entityManager->persist($jobA);
        $this->entityManager->persist($invitationB);
        $this->entityManager->flush();

        return [
            'adminUser' => $adminUser,
        ];
    }
}
