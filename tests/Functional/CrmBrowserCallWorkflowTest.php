<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Service\TelnyxCallControlService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Psr\Log\NullLogger;

final class CrmBrowserCallWorkflowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateDatabase();
        $this->entityManager->clear();
    }

    #[Test]
    public function browserCallStartsAndReturnsBrowserSessionMetadata(): void
    {
        $data = $this->seedTenantData();
        static::getContainer()->set(
            TelnyxCallControlService::class,
            new TelnyxCallControlService(
                new MockHttpClient(static fn (): MockResponse => new MockResponse('{"data":{"call_leg_id":"leg-1"}}', ['http_code' => 200])),
                new NullLogger(),
                'api-key',
            ),
        );
        $this->client->loginUser($data['user']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), ['_token' => ''], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');

        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertArrayHasKey('providerSessionId', $payload);
        self::assertArrayHasKey('eventStreamUrl', $payload);
        self::assertSame(CallSession::CALL_MODE_BROWSER, $payload['callSession']['callMode']);
        self::assertSame(CallSession::CALL_STATE_INITIATED, $payload['callSession']['callState']);
        self::assertSame('/api/calls/'.$payload['providerSessionId'].'/events/stream', $payload['eventStreamUrl']);
        self::assertNotSame('', $payload['browserSessionToken']);
    }

    /**
     * @return array{tenant:Tenant,user:User,property:Property,contact:Contact}
     */
    private function seedTenantData(): array
    {
        $tenant = (new Tenant('Tenant One'))->setEmail('tenant-one@example.com');
        $user = (new User())
            ->setEmail('csr@example.com')
            ->setPassword('unused')
            ->setRoles(['ROLE_USER']);
        $property = new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1');
        $contact = (new Contact($tenant, 'Tenant Contact'))
            ->setPrimaryPhone('+14165550123');

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($property);
        $this->entityManager->persist($contact);
        $this->entityManager->persist((new UserTenantMembership($user, $tenant))->setRoles([UserTenantMembership::ROLE_SALES])->setIsDefault(true));
        $this->entityManager->persist((new PropertyContact($tenant, $property, $contact))->setIsPrimary(true));
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'user' => $user,
            'property' => $property,
            'contact' => $contact,
        ];
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
}
