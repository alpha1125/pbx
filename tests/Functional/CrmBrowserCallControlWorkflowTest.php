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
use Psr\Log\NullLogger;

final class CrmBrowserCallControlWorkflowTest extends WebTestCase
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
    public function hangupEndpointTerminatesCallAndSyncsState(): void
    {
        // Mock the Telnyx call control service
        static::getContainer()->set(
            TelnyxCallControlService::class,
            new TelnyxCallControlService(
                new MockHttpClient(fn () => new MockResponse(json_encode(['data' => ['call_leg_id' => 'leg-1']]), ['http_code' => 200])),
                new NullLogger(),
                'api-key',
            ),
        );

        $data = $this->seedTenantData();

        // Create an active browser call session + browser softphone session
        $callSession = (new CallSession('provider-hangup-test'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_CONNECTED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'hangup-test-token',
        );
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $browserSession->setTelnyxConnectionId('webrtc-conn-hangup');
        $browserSession->setTelnyxCallControlId('cc-hangup-test');
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/hangup',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame(CallSession::CALL_STATE_COMPLETED, $payload['callState']);

        // Verify the call session was updated
        /** @var CallSessionRepository $repo */
        $repo = static::getContainer()->get(CallSessionRepository::class);
        $updated = $repo->findOneBy(['providerSessionId' => $callSession->getProviderSessionId()]);
        self::assertNotNull($updated);
        self::assertSame(CallSession::CALL_STATE_COMPLETED, $updated->getCallState());

        // Verify audit log was written
        /** @var AuditLogRepository $auditLogs */
        $auditLogs = static::getContainer()->get(AuditLogRepository::class);
        $logs = $auditLogs->findBy([
            'entityType' => 'call_session',
            'action' => 'call.browser_call_hungup',
        ], ['createdAt' => 'DESC']);
        self::assertNotEmpty($logs);
    }

    #[Test]
    public function hangupRejectsWhenNoActiveBrowserCallSession(): void
    {
        $data = $this->seedTenantData();
        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);

        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/hangup',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => 'nonexistent-provider-id',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function hangupRejectsWhenCallControlIdIsMissing(): void
    {
        $data = $this->seedTenantData();

        $callSession = (new CallSession('provider-hangup-missing-control'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_CONNECTED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'hangup-missing-control-token',
        );
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $browserSession->setTelnyxConnectionId('webrtc-conn-hangup-missing');
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/hangup',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertStringContainsString('call control id is not available', strtolower((string) $payload['error']));
    }

    #[Test]
    public function recordingEndpointStartsCaptureAndSyncsState(): void
    {
        static::getContainer()->set(
            TelnyxCallControlService::class,
            new TelnyxCallControlService(
                new MockHttpClient(static fn () => new MockResponse(json_encode(['data' => ['ok' => true]]), ['http_code' => 200])),
                new NullLogger(),
                'api-key',
            ),
        );

        $data = $this->seedTenantData();

        // Create active browser call session (no capture yet)
        $callSession = (new CallSession('provider-rec-test'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_CONNECTED)
            ->setRecordingState(CallSession::RECORDING_STATE_INACTIVE)
            ->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_INACTIVE)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'rec-test-token',
        );
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $browserSession->setTelnyxConnectionId('webrtc-conn-rec');
        $browserSession->setTelnyxCallControlId('cc-rec-test');
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/recording',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
            'action' => 'start',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame('start', $payload['action']);
        self::assertSame(CallSession::RECORDING_STATE_ACTIVE, $payload['recordingState']);

        // Verify the call session was updated
        /** @var CallSessionRepository $repo */
        $repo = static::getContainer()->get(CallSessionRepository::class);
        $updated = $repo->findOneBy(['providerSessionId' => $callSession->getProviderSessionId()]);
        self::assertNotNull($updated);
        self::assertSame(CallSession::RECORDING_STATE_ACTIVE, $updated->getRecordingState());
    }

    #[Test]
    public function recordingEndpointRejectsWhenCallControlIdIsMissing(): void
    {
        $data = $this->seedTenantData();

        $callSession = (new CallSession('provider-rec-missing-control'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_CONNECTED)
            ->setRecordingState(CallSession::RECORDING_STATE_INACTIVE)
            ->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_INACTIVE)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'rec-missing-control-token',
        );
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $browserSession->setTelnyxConnectionId('webrtc-conn-rec-missing');
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/recording',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
            'action' => 'start',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['ok']);
        self::assertStringContainsString('call control id is not available', strtolower((string) $payload['error']));
    }

    #[Test]
    public function recordingEndpointStopsCapture(): void
    {
        static::getContainer()->set(
            TelnyxCallControlService::class,
            new TelnyxCallControlService(
                new MockHttpClient(static fn () => new MockResponse(json_encode(['data' => ['ok' => true]]), ['http_code' => 200])),
                new NullLogger(),
                'api-key',
            ),
        );

        $data = $this->seedTenantData();

        // Create active browser call session (already recording)
        $callSession = (new CallSession('provider-rec-stop-test'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_CONNECTED)
            ->setRecordingState(CallSession::RECORDING_STATE_ACTIVE)
            ->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_ACTIVE)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'rec-stop-token',
        );
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $browserSession->setTelnyxConnectionId('webrtc-conn-rec-stop');
        $browserSession->setTelnyxCallControlId('cc-rec-stop-test');
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/recording',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
            'action' => 'stop',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame('stop', $payload['action']);
        self::assertSame(CallSession::RECORDING_STATE_STOPPED, $payload['recordingState']);

        /** @var CallSessionRepository $repo */
        $repo = static::getContainer()->get(CallSessionRepository::class);
        $updated = $repo->findOneBy(['providerSessionId' => $callSession->getProviderSessionId()]);
        self::assertNotNull($updated);
        self::assertSame(CallSession::RECORDING_STATE_STOPPED, $updated->getRecordingState());
    }

    #[Test]
    public function dtmfEndpointSendsDigitsToPlatform(): void
    {
        $data = $this->seedTenantData();

        $callSession = (new CallSession('provider-dtmf-test'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_CONNECTED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'dtmf-test-token',
        );
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $browserSession->setTelnyxConnectionId('webrtc-conn-dtmf');
        $browserSession->setTelnyxCallControlId('cc-dtmf-test');
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        // Mock the call control service for DTMF
        static::getContainer()->set(
            TelnyxCallControlService::class,
            new TelnyxCallControlService(
                new MockHttpClient(fn () => new MockResponse(json_encode(['data' => ['success' => true]]), ['http_code' => 200])),
                new NullLogger(),
                'api-key',
            ),
        );

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/dtmf',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
            'digits' => '1*9#0',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame('1*9#0', $payload['digits']);
    }

    #[Test]
    public function muteEndpointAcceptsMuteAction(): void
    {
        $data = $this->seedTenantData();

        $callSession = (new CallSession('provider-mute-test'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_CONNECTED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $data['tenant'],
            $data['user'],
            'mute-test-token',
        );
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);
        $browserSession->setTelnyxConnectionId('webrtc-conn-mute');
        $browserSession->setTelnyxCallControlId('cc-mute-test');
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        // Mock call control for mute
        static::getContainer()->set(
            TelnyxCallControlService::class,
            new TelnyxCallControlService(
                new MockHttpClient(fn () => new MockResponse(json_encode(['data' => ['success' => true]]), ['http_code' => 200])),
                new NullLogger(),
                'api-key',
            ),
        );

        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);
        $token = $this->browserCallToken($data['property'], $data['contact']);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/mute',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            '_token' => $token,
            'providerSessionId' => $callSession->getProviderSessionId(),
            'action' => 'mute',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame('muted', $payload['state']);
    }

    #[Test]
    public function recordingEndpointRejectsUnauthenticated(): void
    {
        $data = $this->seedTenantData();
        // Don't log in - request should fail

        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call/recording',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), [
            'providerSessionId' => 'test',
            'action' => 'start',
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        // Should be 302 redirect to login (not logged in)
        self::assertResponseStatusCodeSame(302);
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
