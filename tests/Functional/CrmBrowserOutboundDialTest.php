<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AuditLog;
use App\Entity\BrowserSoftphoneSession;
use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\AuditLogRepository;
use App\Repository\BrowserSoftphoneSessionRepository;
use App\Repository\CallSessionRepository;
use App\Service\TelnyxCallControlService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use App\Service\CurrentTenantProviderInterface;
use Psr\Log\NullLogger;

final class CrmBrowserOutboundDialTest extends WebTestCase
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
    public function dialEndpointRejectsWhenBrowserNotConnected(): void
    {
        $data = $this->seedTenantData();
        // Create an allocated session but don't set telnyxConnectionId or ready state
        $callSession = (new CallSession('provider-dial-test'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);
        $this->entityManager->flush();

        // Allocate browser session but don't set telnyxConnectionId (simulating unconnected state)
        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'dial-test-token',
        );
        // Default connection state is idle - NOT ready
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/dial',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertSame('Browser softphone must be connected before dialing.', $payload['error']);
    }

    #[Test]
    public function dialEndpointRejectsWhenNoTelnyxConnectionId(): void
    {
        $data = $this->seedTenantData();

        $callSession = (new CallSession('provider-dial-noid'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);
        $this->entityManager->flush();

        // Allocate with no telnyxConnectionId (connection is null)
        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'dial-noconn-token',
        );
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        // telnyxConnectionId stays null (not set)
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();
        /** @var CallSessionRepository $sessionRepo */
        $sessionRepo = static::getContainer()->get(CallSessionRepository::class);
        $callSession = $sessionRepo->findOneByProviderSessionId($callSession->getProviderSessionId()) ?? $callSession;

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/dial',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertSame('Telnyx WebRTC connection ID not available.', $payload['error']);
    }

    #[Test]
    public function dialEndpointRejectsMissingCsrfToken(): void
    {
        $data = $this->seedTenantData();

        $callSession = (new CallSession('provider-dial-missing-csrf'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);
        $this->entityManager->flush();

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'dial-missing-csrf-token',
        );
        $browserSession->setTelnyxConnectionId('webrtc-conn-csrf-missing');
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/dial',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            'providerSessionId' => $callSession->getProviderSessionId(),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertSame('Invalid CSRF token.', $payload['error']);
    }

    #[Test]
    public function dialEndpointRejectsInvalidCsrfToken(): void
    {
        $data = $this->seedTenantData();

        $callSession = (new CallSession('provider-dial-invalid-csrf'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);
        $this->entityManager->flush();

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'dial-invalid-csrf-token',
        );
        $browserSession->setTelnyxConnectionId('webrtc-conn-csrf-invalid');
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/dial',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => 'not-the-token',
            'providerSessionId' => $callSession->getProviderSessionId(),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertSame('Invalid CSRF token.', $payload['error']);
    }

    #[Test]
    public function dialEndpointSucceedsWithValidSession(): void
    {
        // Mock the Telnyx call control service to return a valid dial response
        static::getContainer()->set(
            TelnyxCallControlService::class,
            new TelnyxCallControlService(
                new MockHttpClient(fn () => new MockResponse(json_encode(['data' => ['call_leg_id' => 'leg-9i-outbound']]), ['http_code' => 200])),
                new NullLogger(),
                'api-key',
            ),
        );

        $data = $this->seedTenantData();

        // Create call session + browser session with valid connection ID
        $callSession = (new CallSession('provider-dial-ok'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);
        $this->entityManager->flush();

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'dial-ok-token',
        );
        $browserSession->setTelnyxConnectionId('webrtc-conn-from-sdk-789');
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();
        /** @var CallSessionRepository $sessionRepo */
        $sessionRepo = static::getContainer()->get(CallSessionRepository::class);
        $callSession = $sessionRepo->findOneByProviderSessionId($callSession->getProviderSessionId()) ?? $callSession;

        // Verify the connection ID is persisted
        /** @var BrowserSoftphoneSessionRepository $repo */
        $repo = static::getContainer()->get(BrowserSoftphoneSessionRepository::class);
        $foundBrowser = $repo->findOneBy(['callSession' => $callSession]);
        self::assertNotNull($foundBrowser);
        self::assertSame('webrtc-conn-from-sdk-789', $foundBrowser->getTelnyxConnectionId());

        // Now call the dial endpoint
        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/dial',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame(CallSession::CALL_MODE_BROWSER, $payload['callMode']);
        self::assertSame(CallSession::CALL_STATE_RINGING, $payload['callState']);
        self::assertArrayHasKey('callLegId', $payload);
        self::assertSame('leg-9i-outbound', $payload['callLegId']);

        // Verify the call session was updated
        /** @var CallSessionRepository $sessionRepo */
        $sessionRepo = static::getContainer()->get(CallSessionRepository::class);
        $updatedSession = $sessionRepo->findOneByProviderSessionId($callSession->getProviderSessionId());
        self::assertNotNull($updatedSession);
        self::assertSame(CallSession::CALL_STATE_RINGING, $updatedSession->getCallState());
        self::assertSame('active', $updatedSession->getStatus());

        // Verify audit log was written
        /** @var AuditLogRepository $auditLogs */
        $auditLogs = static::getContainer()->get(AuditLogRepository::class);
        $logs = $auditLogs->findBy([
            'entityType' => 'call_session',
            'action' => 'call.browser_call_dialed',
        ], ['createdAt' => 'DESC']);
        self::assertNotEmpty($logs);
        self::assertSame((string) $updatedSession->getId(), $logs[0]->getEntityId());
    }

    #[Test]
    public function dialEndpointRejectsCrossTenantProviderSessionAccess(): void
    {
        $data = $this->seedTenantData();

        $callSession = (new CallSession('provider-dial-mismatch'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);
        $this->entityManager->flush();

        $otherTenant = (new Tenant('Other Tenant'))->setEmail('other@example.com');
        $otherUser = (new User())
            ->setEmail('other@example.com')
            ->setPassword('x')
            ->setRoles(['ROLE_USER']);
        $this->entityManager->persist($otherTenant);
        $this->entityManager->persist($otherUser);
        $this->entityManager->flush();

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $otherTenant,
            $otherUser,
            'dial-mismatch-token',
        );
        $browserSession->setTelnyxConnectionId('conn-test');
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();
        /** @var CallSessionRepository $sessionRepo */
        $sessionRepo = static::getContainer()->get(CallSessionRepository::class);
        $callSession = $sessionRepo->findOneByProviderSessionId($callSession->getProviderSessionId()) ?? $callSession;

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/dial',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(), // Valid session, valid tenant
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertSame('Browser softphone session not found.', $payload['error']);
    }

    #[Test]
    public function dialEndpointRejectsRepeatedAttemptAfterCallStateAdvances(): void
    {
        static::getContainer()->set(
            TelnyxCallControlService::class,
            new TelnyxCallControlService(
                new MockHttpClient(fn () => new MockResponse(json_encode(['data' => ['call_leg_id' => 'leg-repeat']]), ['http_code' => 200])),
                new NullLogger(),
                'api-key',
            ),
        );

        $data = $this->seedTenantData();
        $callSession = (new CallSession('provider-dial-repeat'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);
        $this->entityManager->flush();

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'dial-repeat-token',
        );
        $browserSession->setTelnyxConnectionId('webrtc-conn-repeat');
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $request = [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
        ];

        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/dial',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), $request, [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();

        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/dial',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), $request, [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertStringContainsString('already in progress', strtolower((string) $payload['error']));
    }

    #[Test]
    public function dialEndpointRejectsTerminalCallState(): void
    {
        $data = $this->seedTenantData();
        $callSession = (new CallSession('provider-dial-ended'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_COMPLETED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('completed')
            ->touch();
        $this->entityManager->persist($callSession);
        $this->entityManager->flush();

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'dial-ended-token',
        );
        $browserSession->setTelnyxConnectionId('webrtc-conn-ended');
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/dial',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertStringContainsString('already ended', strtolower((string) $payload['error']));
    }

    /**
     * @return array{tenant:Tenant,user:User,property:Property,contact:Contact}
     */
    private function seedTenantData(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = (new Tenant('Tenant One'))->setEmail("tenant-one+{$suffix}@example.com");
        $user = (new User())
            ->setEmail("csr+{$suffix}@example.com")
            ->setPassword('unused')
            ->setRoles(['ROLE_USER']);
        $property = new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1');
        $contact = (new Contact($tenant, "Tenant Contact {$suffix}"))
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

    private function selectTenant(Tenant $tenant): void
    {
        $this->client->request('GET', '/crm/no-tenant');
        $session = $this->client->getRequest()->getSession();
        $session->set('crm.current_tenant_id', $tenant->getId());
        $session->save();
    }

    private function browserCallToken(Property $property, Contact $contact): string
    {
        $this->client->request('GET', sprintf('/crm/properties/%d', $property->getId()));
        $crawler = $this->client->getCrawler();
        $tokenNode = $crawler->filter('[data-controller="browser-softphone"][data-browser-softphone-csrf-token-value]')->first();

        return (string) $tokenNode->attr('data-browser-softphone-csrf-token-value');
    }
}
