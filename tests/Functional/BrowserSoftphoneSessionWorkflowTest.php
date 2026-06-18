<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CallSession;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BrowserSoftphoneSessionWorkflowTest extends WebTestCase
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
    public function browserSessionAllocationReturnsPbxSessionData(): void
    {
        $data = $this->seedTenantData();
        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $this->client->request('POST', sprintf('/api/calls/%s/browser-session', $data['callSession']->getProviderSessionId()));

        self::assertResponseIsSuccessful();
        self::assertResponseFormatSame('json');

        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertArrayHasKey('browserSoftphoneSessionId', $payload);
        self::assertArrayHasKey('browserSessionToken', $payload);
        self::assertSame($data['callSession']->getId(), $payload['callSession']['id']);
        self::assertSame($data['callSession']->getProviderSessionId(), $payload['callSession']['providerSessionId']);
        self::assertSame(CallSession::CALL_MODE_BROWSER, $payload['callSession']['callMode']);
        self::assertSame('/api/calls/'.$data['callSession']->getProviderSessionId().'/events/stream', $payload['eventStreamUrl']);
    }

    #[Test]
    public function browserSessionAllocationIsTenantScoped(): void
    {
        $data = $this->seedTenantData();
        $this->client->loginUser($data['otherUser']);
        $this->selectTenant($data['otherTenant']);
        $this->client->request('POST', sprintf('/api/calls/%s/browser-session', $data['callSession']->getProviderSessionId()));

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function browserSessionConnectionEventsArePersisted(): void
    {
        $data = $this->seedTenantData();
        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $this->client->request('POST', sprintf('/api/calls/%s/browser-session', $data['callSession']->getProviderSessionId()));
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);

        $this->client->request(
            'POST',
            sprintf('/api/browser-softphone-sessions/%s/events', $payload['browserSessionToken']),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode([
                'event' => 'sdk_ready',
                'meta' => ['sdk' => 'telnyx'],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $eventPayload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($eventPayload['ok']);
        self::assertSame('sdk_ready', $eventPayload['connectionState']);
        self::assertSame('active', $eventPayload['status']);
        self::assertNull($eventPayload['connectionErrorMessage']);
    }

    #[Test]
    public function browserSessionCallEventsArePersistedAndRejectStaleIdentifiers(): void
    {
        $data = $this->seedTenantData();
        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $this->client->request('POST', sprintf('/api/calls/%s/browser-session', $data['callSession']->getProviderSessionId()));
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);

        $this->client->request(
            'POST',
            sprintf('/api/browser-softphone-sessions/%s/events', $payload['browserSessionToken']),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode([
                'event' => 'call.requesting',
                'callId' => 'call-id-1',
                'destinationNumber' => $data['callSession']->getClientPhoneNumber(),
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $eventPayload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($eventPayload['ok']);
        self::assertSame('dialing', $eventPayload['callState']);
        self::assertSame('call-id-1', $eventPayload['callId']);

        $this->client->request(
            'POST',
            sprintf('/api/browser-softphone-sessions/%s/events', $payload['browserSessionToken']),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode([
                'event' => 'call.ringing',
                'callId' => 'call-id-1',
                'destinationNumber' => '+19999999999',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
        $failurePayload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($failurePayload['ok']);
        self::assertStringContainsString('approved destination', strtolower((string) $failurePayload['error']));
    }

    /**
     * @return array{tenant:Tenant,otherTenant:Tenant,user:User,otherUser:User,callSession:CallSession}
     */
    private function seedTenantData(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = (new Tenant('Tenant One'))->setEmail("tenant-one+{$suffix}@example.com");
        $otherTenant = (new Tenant('Tenant Two'))->setEmail("tenant-two+{$suffix}@example.com");
        $user = (new User())
            ->setEmail("csr+{$suffix}@example.com")
            ->setPassword('unused')
            ->setRoles(['ROLE_USER']);
        $otherUser = (new User())
            ->setEmail("other+{$suffix}@example.com")
            ->setPassword('unused')
            ->setRoles(['ROLE_USER']);
        $callSession = (new CallSession('browser-session-provider-1'))
            ->setTenant($tenant)
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED);

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($otherTenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($otherUser);
        $this->entityManager->persist((new UserTenantMembership($user, $tenant))->setRoles([UserTenantMembership::ROLE_SALES])->setIsDefault(true));
        $this->entityManager->persist((new UserTenantMembership($otherUser, $otherTenant))->setRoles([UserTenantMembership::ROLE_SALES])->setIsDefault(true));
        $this->entityManager->persist($callSession);
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'otherTenant' => $otherTenant,
            'user' => $user,
            'otherUser' => $otherUser,
            'callSession' => $callSession,
        ];
    }

    private function selectTenant(Tenant $tenant): void
    {
        $this->client->request('GET', '/crm');
        $session = $this->client->getRequest()->getSession();
        $session->set('crm.current_tenant_id', $tenant->getId());
        $session->save();
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

    #[Test]
    public function sdkReadyEventWithTelnyxConnectionIdPersistsId(): void
    {
        // Phase 9I.2: When the browser reports sdk_ready with a telnyxConnectionId,
        // the backend must capture and persist it on the BrowserSoftphoneSession.
        $data = $this->seedTenantData();
        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);

        // Allocate browser session.
        $this->client->request('POST', sprintf('/api/calls/%s/browser-session', $data['callSession']->getProviderSessionId()));
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);

        // Report sdk_ready with a telnyxConnectionId.
        $telnyxConnId = 'webrtc-conn-from-sdk-9i2-test';
        $this->client->request(
            'POST',
            sprintf('/api/browser-softphone-sessions/%s/events', $payload['browserSessionToken']),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: json_encode([
                'event' => 'sdk_ready',
                'telnyxConnectionId' => $telnyxConnId,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $eventPayload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($eventPayload['ok']);
        self::assertSame('sdk_ready', $eventPayload['connectionState']);
        self::assertSame('active', $eventPayload['status']);

        // Verify persistence via repository.
        /** @var \App\Repository\BrowserSoftphoneSessionRepository $repo */
        $repo = static::getContainer()->get(\App\Repository\BrowserSoftphoneSessionRepository::class);
        $stored = $repo->findOneBy(['sessionToken' => $payload['browserSessionToken']]);
        self::assertInstanceOf(\App\Entity\BrowserSoftphoneSession::class, $stored);
        self::assertSame($telnyxConnId, $stored->getTelnyxConnectionId());
    }
}
