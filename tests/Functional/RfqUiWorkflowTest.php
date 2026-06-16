<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\Rfq;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\RfqRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RfqUiWorkflowTest extends WebTestCase
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

    public function testCreateEditAndListScreensSupportTenantScopedMatching(): void
    {
        $data = $this->seedData();

        $this->client->loginUser($data['user']);

        $crawler = $this->client->request('GET', '/crm/rfqs/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Add RFQ');
        self::assertSelectorTextContains('body', $data['tenantProperty']->getDisplayAddress());
        self::assertSelectorTextContains('body', $data['tenantContact']->getDisplayName());
        self::assertSelectorTextNotContains('body', $data['otherProperty']->getDisplayAddress());
        self::assertSelectorTextNotContains('body', $data['otherContact']->getDisplayName());

        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/crm/rfqs/new', [
            '_token' => $token,
            'propertyMatchId' => (string) $data['tenantProperty']->getId(),
            'contactMatchId' => (string) $data['tenantContact']->getId(),
            'externalReference' => 'TP-RFQ-3001',
            'projectType' => 'heat_pump_replacement',
            'description' => 'Need a replacement heat pump for the front unit.',
        ]);

        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('#^/crm/rfqs/\d+$#', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $data['tenantProperty']->getAddressLine1());
        self::assertSelectorTextContains('body', $data['tenantContact']->getDisplayName());
        self::assertSelectorTextContains('body', 'Need a replacement heat pump for the front unit.');

        $rfq = static::getContainer()->get(RfqRepository::class)->findOneByExternalReference('TP-RFQ-3001');
        self::assertInstanceOf(Rfq::class, $rfq);
        self::assertSame(Rfq::STATUS_SUBMITTED, $rfq->getStatus());
        self::assertSame($data['tenantProperty']->getAddressLine1(), $rfq->getAddressLine1());
        self::assertSame($data['tenantProperty']->getPostalCode(), $rfq->getPostalCode());
        self::assertSame($data['tenantContact']->getDisplayName(), $rfq->getCustomerName());
        self::assertSame($data['tenantContact']->getPrimaryPhone(), $rfq->getCustomerPhone());
        self::assertSame($data['tenantContact']->getPrimaryEmail(), $rfq->getCustomerEmail());

        $this->client->request('GET', '/crm/rfqs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'TP-RFQ-3001');

        $crawler = $this->client->request('GET', '/crm/rfqs/'.$rfq->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $data['tenantProperty']->getDisplayAddress());
        self::assertSelectorTextContains('body', $data['tenantContact']->getDisplayName());
        self::assertSelectorTextNotContains('body', $data['otherProperty']->getDisplayAddress());
        self::assertSelectorTextNotContains('body', $data['otherContact']->getDisplayName());

        $editToken = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/crm/rfqs/'.$rfq->getId().'/edit', [
            '_token' => $editToken,
            'propertyMatchId' => (string) $data['otherProperty']->getId(),
            'contactMatchId' => (string) $data['otherContact']->getId(),
            'addressLine1' => '999 Edited Avenue',
            'city' => 'Ottawa',
            'province' => 'ON',
            'postalCode' => 'K1A0B1',
            'country' => 'CA',
            'customerName' => 'Edited Name',
            'customerPhone' => '+16135550111',
            'customerEmail' => 'edited@example.com',
            'projectType' => 'furnace_replacement',
            'description' => 'Updated details.',
        ]);

        self::assertResponseRedirects('/crm/rfqs/'.$rfq->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '999 Edited Avenue');
        self::assertSelectorTextContains('body', 'Edited Name');
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
     *   user: User,
     *   tenantProperty: Property,
     *   tenantContact: Contact,
     *   otherProperty: Property,
     *   otherContact: Contact
     * }
     */
    private function seedData(): array
    {
        $tenant = (new Tenant('Tenant One'))->setEmail('tenant1@example.com');
        $otherTenant = (new Tenant('Tenant Two'))->setEmail('tenant2@example.com');
        $user = (new User())->setEmail('user@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);
        $this->entityManager->persist($tenant);
        $this->entityManager->persist($otherTenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist(
            (new UserTenantMembership($user, $tenant))
                ->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])
                ->setIsDefault(true),
        );

        $tenantProperty = new Property($tenant, '123 Tenant Street', 'Toronto', 'ON', 'M5V2T6');
        $tenantContact = (new Contact($tenant, 'Tenant Contact'))
            ->setPrimaryPhone('+14165550123')
            ->setPrimaryEmail('tenant@example.com');
        $otherProperty = new Property($otherTenant, '999 Other Street', 'Calgary', 'AB', 'T2P1J9');
        $otherContact = (new Contact($otherTenant, 'Other Contact'))
            ->setPrimaryPhone('+14035550111')
            ->setPrimaryEmail('other@example.com');

        $this->entityManager->persist($tenantProperty);
        $this->entityManager->persist($tenantContact);
        $this->entityManager->persist($otherProperty);
        $this->entityManager->persist($otherContact);
        $this->entityManager->persist(
            (new PropertyContact($tenant, $tenantProperty, $tenantContact))
                ->setRelationshipType(PropertyContact::RELATIONSHIP_OWNER)
                ->setIsPrimary(true),
        );
        $this->entityManager->flush();

        return [
            'user' => $user,
            'tenantProperty' => $tenantProperty,
            'tenantContact' => $tenantContact,
            'otherProperty' => $otherProperty,
            'otherContact' => $otherContact,
        ];
    }
}
