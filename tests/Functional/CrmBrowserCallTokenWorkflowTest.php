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
use App\Repository\AuditLogRepository;
use App\Repository\CallSessionRepository;
use App\Service\TelnyxWebrtcTokenService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CrmBrowserCallTokenWorkflowTest extends WebTestCase
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
        $this->clearCache();
        $this->entityManager->clear();
    }

    #[Test]
    public function prepareBrowserCallReturnsShortLivedTokenAndIntentMetadata(): void
    {
        $data = $this->seedTenantData();
        static::getContainer()->set(
            TelnyxWebrtcTokenService::class,
            $this->mockTokenService('eyJ0eXAiOiJKV1QifQ.eyJleHAiOjE4OTM0NTYwMDB9.sig'),
        );

        $this->client->loginUser($data['user']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/prepare',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame(CallSession::CALL_MODE_BROWSER, $payload['callMode']);
        self::assertSame($data['contact']->getPrimaryPhone(), $payload['approvedDestinationNumber']);
        self::assertSame('/api/calls/'.$payload['providerSessionId'].'/events/stream', $payload['statusStreamUrl']);
        self::assertNotEmpty($payload['token']);
        self::assertSame('2030-01-01T00:00:00+00:00', $payload['tokenExpiresAt']);

        /** @var CallSessionRepository $sessions */
        $sessions = static::getContainer()->get(CallSessionRepository::class);
        $session = $sessions->findOneByProviderSessionId($payload['providerSessionId']);
        self::assertNotNull($session);
        self::assertSame(CallSession::CALL_MODE_BROWSER, $session->getCallMode());
        self::assertSame($data['contact']->getPrimaryPhone(), $session->getClientPhoneNumber());

        /** @var AuditLogRepository $auditLogs */
        $auditLogs = static::getContainer()->get(AuditLogRepository::class);
        $logs = $auditLogs->findBy([
            'entityType' => 'call_session',
            'action' => 'call.browser_call_token_issued',
        ], ['createdAt' => 'DESC']);
        self::assertNotEmpty($logs);
        self::assertSame((string) $session->getId(), $logs[0]->getEntityId());
        self::assertSame($payload['providerSessionId'], $logs[0]->getMetadata()['providerSessionId'] ?? null);
    }

    #[Test]
    public function prepareBrowserCallIsRateLimitedForRepeatedRequests(): void
    {
        $data = $this->seedTenantData();
        static::getContainer()->set(
            TelnyxWebrtcTokenService::class,
            $this->mockTokenService('eyJ0eXAiOiJKV1QifQ.eyJleHAiOjE4OTM0NTYwMDB9.sig'),
        );

        $this->client->loginUser($data['user']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/prepare',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/prepare',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(429);

        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertStringContainsString('rate-limited', strtolower((string) $payload['error']));
    }

    #[Test]
    public function prepareBrowserCallRejectsInvalidDestinationNumber(): void
    {
        $data = $this->seedTenantData('+1');
        $this->client->loginUser($data['user']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/prepare',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertStringContainsString('valid primary phone number', strtolower((string) $payload['error']));
    }

    #[Test]
    public function prepareBrowserCallIsTenantScoped(): void
    {
        $data = $this->seedTenantData();
        $this->client->loginUser($data['viewerUser']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/prepare',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array{tenant:Tenant,otherTenant:Tenant,user:User,otherUser:User,viewerUser:User,property:Property,contact:Contact}
     */
    private function seedTenantData(?string $primaryPhone = '+14165550123'): array
    {
        $tenant = (new Tenant('Tenant One'))->setEmail('tenant-one@example.com');
        $otherTenant = (new Tenant('Tenant Two'))->setEmail('tenant-two@example.com');
        $user = (new User())
            ->setEmail('csr@example.com')
            ->setPassword('unused')
            ->setRoles(['ROLE_USER']);
        $otherUser = (new User())
            ->setEmail('other@example.com')
            ->setPassword('unused')
            ->setRoles(['ROLE_USER']);
        $viewerUser = (new User())
            ->setEmail('viewer@example.com')
            ->setPassword('unused')
            ->setRoles(['ROLE_USER']);
        $property = new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1');
        $contact = (new Contact($tenant, 'Tenant Contact'))
            ->setPrimaryPhone($primaryPhone);

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($otherTenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($otherUser);
        $this->entityManager->persist($viewerUser);
        $this->entityManager->persist($property);
        $this->entityManager->persist($contact);
        $this->entityManager->persist((new UserTenantMembership($user, $tenant))->setRoles([UserTenantMembership::ROLE_SALES])->setIsDefault(true));
        $this->entityManager->persist((new UserTenantMembership($otherUser, $otherTenant))->setRoles([UserTenantMembership::ROLE_SALES])->setIsDefault(true));
        $this->entityManager->persist((new UserTenantMembership($viewerUser, $tenant))->setRoles([])->setIsDefault(false));
        $this->entityManager->persist((new PropertyContact($tenant, $property, $contact))->setIsPrimary(true));
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'otherTenant' => $otherTenant,
            'user' => $user,
            'otherUser' => $otherUser,
            'viewerUser' => $viewerUser,
            'property' => $property,
            'contact' => $contact,
        ];
    }

    private function mockTokenService(string $token): TelnyxWebrtcTokenService
    {
        $service = $this->createStub(TelnyxWebrtcTokenService::class);
        $service->method('issue')->willReturn([
            'token' => $token,
            'expiresAt' => new \DateTimeImmutable('@1893456000'),
            'rawResponse' => $token,
        ]);

        return $service;
    }

    private function clearCache(): void
    {
        static::getContainer()->get(\Psr\Cache\CacheItemPoolInterface::class)->clear();
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
