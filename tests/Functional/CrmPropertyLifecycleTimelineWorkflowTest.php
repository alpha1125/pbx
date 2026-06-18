<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CallSession;
use App\Entity\CustomerSentimentHistory;
use App\Entity\Equipment;
use App\Entity\Invoice;
use App\Entity\MaintenancePlan;
use App\Entity\Property;
use App\Entity\PropertyMaintenancePlan;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Entity\RetentionOpportunity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CrmPropertyLifecycleTimelineWorkflowTest extends WebTestCase
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

    public function testHomeownerTimelineRendersAndFiltersOnPropertyPage(): void
    {
        $data = $this->seedData();
        $this->client->loginUser($data['user']);

        $crawler = $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#homeowner-lifecycle-card');
        self::assertSelectorTextContains('#homeowner-lifecycle-card', 'Homeowner Timeline');
        self::assertCount(6, $crawler->filter('#homeowner-lifecycle-card .list-group-item'));
        self::assertSelectorTextContains('#homeowner-lifecycle-card', 'INV-2001');

        $crawler = $this->client->request(
            'GET',
            '/crm/properties/'.$data['property']->getId().'?lifecycleType=invoice&lifecycleQ=INV-2001',
        );
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#homeowner-lifecycle-card .list-group-item'));
        self::assertSelectorTextContains('#homeowner-lifecycle-card', 'Invoice');
        self::assertSelectorTextContains('#homeowner-lifecycle-card', 'INV-2001');
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
     * @return array{tenant: Tenant, user: User, property: Property}
     */
    private function seedData(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = (new Tenant('Tenant One '.$suffix))->setEmail(sprintf('tenant-%s@example.com', $suffix));
        $user = (new User())->setEmail(sprintf('csr-%s@example.com', $suffix))->setPassword('unused')->setRoles(['ROLE_USER']);
        $property = new Property($tenant, '100 Main St '.$suffix, 'Toronto', 'ON', 'M1M1M1');
        $equipment = (new Equipment($tenant, $property, Equipment::TYPE_FURNACE))
            ->setBrand('Acme')
            ->setInstalledAt(new \DateTimeImmutable('2026-06-01 09:00:00'));
        $invoice = (new Invoice($tenant, $property, 'INV-2001-'.$suffix))
            ->setStatus(Invoice::STATUS_SENT)
            ->setSentAt(new \DateTimeImmutable('2026-06-02 09:00:00'));
        $callSession = (new CallSession('provider-session-'.$suffix))
            ->setTenant($tenant)
            ->setProperty($property)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_COMPLETED)
            ->setStartedAt(new \DateTimeImmutable('2026-06-03 08:00:00'))
            ->setEndedAt(new \DateTimeImmutable('2026-06-03 08:30:00'));
        $sentiment = new CustomerSentimentHistory(
            $tenant,
            $property,
            $user,
            CustomerSentimentHistory::SENTIMENT_POSITIVE,
            'Customer is happy after the repair.',
            new \DateTimeImmutable('2026-06-04 09:00:00'),
        );
        $maintenancePlan = new MaintenancePlan($tenant, 'Gold Protection');
        $assignment = (new PropertyMaintenancePlan($tenant, $property, $maintenancePlan))
            ->cancel(new \DateTimeImmutable('2026-06-05 09:00:00'));
        $opportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_OPEN_INVOICE,
            'invoice:2001:'.$suffix,
            'Open invoice balance.',
            null,
            null,
            new \DateTimeImmutable('2026-06-06 09:00:00'),
        );

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist((new UserTenantMembership($user, $tenant))->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])->setIsDefault(true));
        $this->entityManager->persist($property);
        $this->entityManager->persist($equipment);
        $this->entityManager->persist($invoice);
        $this->entityManager->persist($callSession);
        $this->entityManager->persist($sentiment);
        $this->entityManager->persist($maintenancePlan);
        $this->entityManager->persist($assignment);
        $this->entityManager->persist($opportunity);
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'user' => $user,
            'property' => $property,
        ];
    }
}
