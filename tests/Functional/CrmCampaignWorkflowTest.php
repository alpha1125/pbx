<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Campaign;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\CampaignRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CrmCampaignWorkflowTest extends WebTestCase
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

    public function testCampaignCrudAndStatusManagement(): void
    {
        $data = $this->seedData();

        $this->client->loginUser($data['user']);

        $crawler = $this->client->request('GET', '/crm/campaigns');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Campaigns');
        self::assertSelectorTextContains('body', 'No campaigns yet.');

        $crawler = $this->client->request('GET', '/crm/campaigns/new');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/crm/campaigns/new"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/crm/campaigns/new', [
            '_token' => $token,
            'name' => 'Spring Tune-Up Campaign',
            'campaignType' => Campaign::TYPE_SPRING_AC_TUNE_UP,
            'audienceDescription' => 'Owners of properties with older AC equipment.',
            'scheduledDate' => '2026-07-15',
            'status' => Campaign::STATUS_SCHEDULED,
            'notes' => 'Coordinate with dispatch before launch.',
        ]);

        self::assertResponseRedirects('/crm/campaigns');

        $this->entityManager->clear();
        $campaignRepository = static::getContainer()->get(CampaignRepository::class);
        $campaigns = $campaignRepository->findByTenantOrdered($data['tenant']);
        self::assertCount(1, $campaigns);
        self::assertSame('Spring Tune-Up Campaign', $campaigns[0]->getName());
        self::assertSame(Campaign::STATUS_SCHEDULED, $campaigns[0]->getStatus());
        self::assertSame('2026-07-15', $campaigns[0]->getScheduledDate()?->format('Y-m-d'));

        $crawler = $this->client->request('GET', '/crm/campaigns/'.$campaigns[0]->getId().'/edit');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/crm/campaigns/'.$campaigns[0]->getId().'/edit"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/crm/campaigns/'.$campaigns[0]->getId().'/edit', [
            '_token' => $token,
            'name' => 'Spring Tune-Up Campaign Updated',
            'campaignType' => Campaign::TYPE_MAINTENANCE_RENEWAL,
            'audienceDescription' => 'Updated audience description.',
            'scheduledDate' => '2026-08-01',
            'status' => Campaign::STATUS_APPROVED,
            'notes' => 'Ready for approval.',
        ]);

        self::assertResponseRedirects('/crm/campaigns');

        $this->entityManager->clear();
        $updatedCampaign = $campaignRepository->findOneByTenantAndId($data['tenant'], $campaigns[0]->getId());
        self::assertInstanceOf(Campaign::class, $updatedCampaign);
        self::assertSame('Spring Tune-Up Campaign Updated', $updatedCampaign->getName());
        self::assertSame(Campaign::TYPE_MAINTENANCE_RENEWAL, $updatedCampaign->getCampaignType());
        self::assertSame(Campaign::STATUS_APPROVED, $updatedCampaign->getStatus());
        self::assertSame('2026-08-01', $updatedCampaign->getScheduledDate()?->format('Y-m-d'));
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
     * @return array{tenant: Tenant, user: User}
     */
    private function seedData(): array
    {
        $tenant = (new Tenant('Tenant One'))->setEmail('tenant@example.com');
        $user = (new User())->setEmail('admin@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist(
            (new UserTenantMembership($user, $tenant))
                ->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])
                ->setIsDefault(true),
        );
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'user' => $user,
        ];
    }
}
