<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Contact;
use App\Entity\Estimate;
use App\Entity\Property;
use App\Entity\Quote;
use App\Entity\Rfq;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\TenantRepository;
use App\Repository\RfqInvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VendorPortalWorkflowTest extends WebTestCase
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

    public function testVendorPortalShowsQueueProgressAndUpdatesPreferences(): void
    {
        $data = $this->seedData();

        $this->client->loginUser($data['adminUser']);

        $crawler = $this->client->request('GET', '/crm/vendor-portal');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Vendor Portal');
        self::assertSelectorTextContains('body', 'Invitations with quote progress');
        self::assertSelectorTextContains('body', 'Awaiting estimate creation');
        self::assertSelectorTextContains('body', 'Open Quote');
        self::assertSelectorTextContains('body', 'Accept');
        self::assertSelectorTextContains('body', 'Decline');

        $token = $crawler->filter('form[action="/crm/vendor-portal"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/crm/vendor-portal', [
            '_token' => $token,
            'rfqVendorEmailNotificationsEnabled' => '0',
            'rfqVendorSmsNotificationsEnabled' => '1',
        ]);

        self::assertResponseRedirects('/crm/vendor-portal');

        $this->entityManager->clear();
        $tenant = static::getContainer()->get(TenantRepository::class)->find($data['vendorTenant']->getId());
        self::assertInstanceOf(Tenant::class, $tenant);
        self::assertFalse($tenant->isRfqVendorEmailNotificationsEnabled());
        self::assertTrue($tenant->isRfqVendorSmsNotificationsEnabled());

        $this->client->followRedirect();
        $acceptToken = $this->client->getCrawler()->filter('form[action="/crm/rfq-invitations/'.$data['acceptInvitation']->getId().'/accept"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/rfq-invitations/%d/accept', $data['acceptInvitation']->getId()), [
            '_token' => $acceptToken,
        ]);
        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#^/crm/estimates/\d+$#', (string) $this->client->getResponse()->headers->get('Location'));

        $crawler = $this->client->request('GET', '/crm/vendor-portal');
        $declineToken = $crawler->filter('form[action="/crm/rfq-invitations/'.$data['declineInvitation']->getId().'/decline"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/rfq-invitations/%d/decline', $data['declineInvitation']->getId()), [
            '_token' => $declineToken,
        ]);
        self::assertResponseRedirects('/crm/rfq-invitations');

        $this->entityManager->clear();
        $invitationRepository = static::getContainer()->get(RfqInvitationRepository::class);
        $acceptedInvitation = $invitationRepository->find($data['acceptInvitation']->getId());
        $declinedInvitation = $invitationRepository->find($data['declineInvitation']->getId());
        self::assertInstanceOf(RfqInvitation::class, $acceptedInvitation);
        self::assertInstanceOf(RfqInvitation::class, $declinedInvitation);
        self::assertSame(RfqInvitation::STATUS_ACCEPTED_FOR_QUOTE, $acceptedInvitation->getStatus());
        self::assertSame(RfqInvitation::STATUS_DECLINED, $declinedInvitation->getStatus());
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
     *   vendorTenant: Tenant,
     *   adminUser: User,
     *   acceptInvitation: RfqInvitation,
     *   declineInvitation: RfqInvitation
     * }
     */
    private function seedData(): array
    {
        $vendorTenant = (new Tenant('Vendor Portal HVAC'))->setEmail('vendor@example.com')->setRfqVendorEnabled(true);
        $adminUser = (new User())->setEmail('admin@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);
        $this->entityManager->persist($vendorTenant);
        $this->entityManager->persist($adminUser);
        $this->entityManager->persist(
            (new UserTenantMembership($adminUser, $vendorTenant))
                ->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])
                ->setIsDefault(true),
        );

        $property = new Property($vendorTenant, '100 Portal Street', 'Toronto', 'ON', 'M5V2T6');
        $contact = (new Contact($vendorTenant, 'Vendor Contact'))
            ->setPrimaryEmail('contact@example.com')
            ->setPrimaryPhone('+14165550123');
        $this->entityManager->persist($property);
        $this->entityManager->persist($contact);

        $acceptRfq = (new Rfq('101 Portal Street', 'Toronto', 'ON', 'M5V2T7'))->setStatus(Rfq::STATUS_SENT_TO_VENDORS);
        $declineRfq = (new Rfq('102 Portal Street', 'Toronto', 'ON', 'M5V2T8'))->setStatus(Rfq::STATUS_SENT_TO_VENDORS);
        $progressRfq = (new Rfq('103 Portal Street', 'Toronto', 'ON', 'M5V2T9'))->setStatus(Rfq::STATUS_SENT_TO_VENDORS);
        $this->entityManager->persist($acceptRfq);
        $this->entityManager->persist($declineRfq);
        $this->entityManager->persist($progressRfq);

        $acceptInvitation = new RfqInvitation($vendorTenant, $acceptRfq);
        $declineInvitation = new RfqInvitation($vendorTenant, $declineRfq);
        $progressInvitation = new RfqInvitation($vendorTenant, $progressRfq);
        $this->entityManager->persist($acceptInvitation);
        $this->entityManager->persist($declineInvitation);
        $this->entityManager->persist($progressInvitation);

        $estimate = (new Estimate($vendorTenant, $property))
            ->setContact($contact)
            ->setRfqInvitation($progressInvitation)
            ->setStatus(Estimate::STATUS_DRAFT)
            ->setTitle('Portal estimate');
        $this->entityManager->persist($estimate);
        $progressInvitation->setCreatedEstimate($estimate);

        $quote = (new Quote($vendorTenant, $property, 'Q-PORTAL-1'))
            ->setContact($contact)
            ->setEstimate($estimate)
            ->setStatus(Quote::STATUS_SENT)
            ->setSentAt(new \DateTimeImmutable('2026-06-20 10:00:00'))
            ->setShareToken('portal-quote-token');
        $this->entityManager->persist($quote);

        $this->entityManager->flush();

        return [
            'vendorTenant' => $vendorTenant,
            'adminUser' => $adminUser,
            'acceptInvitation' => $acceptInvitation,
            'declineInvitation' => $declineInvitation,
        ];
    }
}
