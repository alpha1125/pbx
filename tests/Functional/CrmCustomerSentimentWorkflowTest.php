<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\CustomerSentimentHistory;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\CustomerSentimentHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CrmCustomerSentimentWorkflowTest extends WebTestCase
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

    public function testSentimentCanBeRecordedAndShownOnPropertyPage(): void
    {
        $data = $this->seedData();
        $this->client->loginUser($data['user']);

        $crawler = $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Customer Sentiment');
        self::assertSelectorTextContains('body', 'No sentiment history yet.');

        $token = $crawler->filter(sprintf('form[action="/crm/properties/%d/sentiments"] input[name="_token"]', $data['property']->getId()))->attr('value');
        $this->client->request('POST', '/crm/properties/'.$data['property']->getId().'/sentiments', [
            '_token' => $token,
            'sentiment' => CustomerSentimentHistory::SENTIMENT_POSITIVE,
            'contactId' => $data['contact']->getId(),
            'callSessionId' => $data['callSession']->getId(),
            'note' => 'Customer confirmed the system is operating well after service.',
        ]);

        self::assertResponseRedirects('/crm/properties/'.$data['property']->getId());

        $this->entityManager->clear();
        $repository = static::getContainer()->get(CustomerSentimentHistoryRepository::class);
        $entries = $repository->findByTenantAndProperty($data['tenant'], $data['property'], 10);
        self::assertCount(1, $entries);
        self::assertSame(CustomerSentimentHistory::SENTIMENT_POSITIVE, $entries[0]->getSentiment());
        self::assertSame('Customer confirmed the system is operating well after service.', $entries[0]->getNote());
        self::assertSame($data['contact']->getId(), $entries[0]->getContact()?->getId());
        self::assertSame($data['callSession']->getId(), $entries[0]->getCallSession()?->getId());

        $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Positive');
        self::assertSelectorTextContains('body', 'Customer confirmed the system is operating well after service.');
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
     * @return array{tenant: Tenant, user: User, property: Property, contact: Contact, callSession: CallSession}
     */
    private function seedData(): array
    {
        $tenant = (new Tenant('Tenant One'))->setEmail('tenant@example.com');
        $user = (new User())->setEmail('csr@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $contact = (new Contact($tenant, 'Primary Contact'))->setPrimaryPhone('+14165550123');
        $callSession = (new CallSession('provider-session-1'))
            ->setTenant($tenant)
            ->setProperty($property)
            ->setContact($contact)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_COMPLETED)
            ->setStartedAt(new \DateTimeImmutable('-1 hour'))
            ->setEndedAt(new \DateTimeImmutable('-50 minutes'));

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist((new UserTenantMembership($user, $tenant))->setRoles([UserTenantMembership::ROLE_SALES])->setIsDefault(true));
        $this->entityManager->persist($property);
        $this->entityManager->persist($contact);
        $this->entityManager->persist((new PropertyContact($tenant, $property, $contact))->setIsPrimary(true));
        $this->entityManager->persist($callSession);
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'user' => $user,
            'property' => $property,
            'contact' => $contact,
            'callSession' => $callSession,
        ];
    }
}
