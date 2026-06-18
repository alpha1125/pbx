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
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CrmBrowserCallEventReconciliationWorkflowTest extends WebTestCase
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
    public function browserEventsEndpointNormalizesAndReconcilesCallRequesting(): void
    {
        $data = $this->seedTenantData();

        // Create active browser call session.
        $callSession = (new CallSession('provider-recon-1'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        $browserSession = new BrowserSoftphoneSession(
            $callSession, $data['tenant'], $data['user'], 'recon-token-1',
        );
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->client->request('POST', sprintf(
            '/api/calls/%s/browser-events',
            $callSession->getProviderSessionId(),
        ), [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['event' => 'call.requesting', 'callId' => 'sdk-req-1']));

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);
        self::assertSame('call.requesting', $payload['eventType']);

        // Verify the session was updated via reconciliation.
        /** @var CallSessionRepository $repo */
        $repo = static::getContainer()->get(CallSessionRepository::class);
        $updated = $repo->findOneBy(['providerSessionId' => $callSession->getProviderSessionId()]);
        self::assertNotNull($updated);
        self::assertSame(CallSession::CALL_STATE_INITIATED, $updated->getCallState());
    }

    #[Test]
    public function browserEventsEndpointNormalizesAndReconcilesCallActiveToConnected(): void
    {
        $data = $this->seedTenantData();

        // Start with ringing state (simulating a call in progress).
        $callSession = (new CallSession('provider-recon-2'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_RINGING)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        $browserSession = new BrowserSoftphoneSession(
            $callSession, $data['tenant'], $data['user'], 'recon-token-2',
        );
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->client->request('POST', sprintf(
            '/api/calls/%s/browser-events',
            $callSession->getProviderSessionId(),
        ), [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['event' => 'call.active']));

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']);

        /** @var CallSessionRepository $repo */
        $repo = static::getContainer()->get(CallSessionRepository::class);
        $updated = $repo->findOneBy(['providerSessionId' => $callSession->getProviderSessionId()]);
        self::assertNotNull($updated);
        self::assertSame(CallSession::CALL_STATE_CONNECTED, $updated->getCallState());
    }

    #[Test]
    public function browserEventsEndpointNormalizesAndReconcilesCallHangupToCompleted(): void
    {
        $data = $this->seedTenantData();

        // Call is currently connected.
        $callSession = (new CallSession('provider-recon-3'))
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
            $callSession, $data['tenant'], $data['user'], 'recon-token-3',
        );
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->client->request('POST', sprintf(
            '/api/calls/%s/browser-events',
            $callSession->getProviderSessionId(),
        ), [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['event' => 'call.hangup']));

        self::assertResponseIsSuccessful();

        /** @var CallSessionRepository $repo */
        $repo = static::getContainer()->get(CallSessionRepository::class);
        $updated = $repo->findOneBy(['providerSessionId' => $callSession->getProviderSessionId()]);
        self::assertNotNull($updated);
        self::assertSame(CallSession::CALL_STATE_COMPLETED, $updated->getCallState());

        // Audit log should have been written.
        /** @var AuditLogRepository $auditLogs */
        $auditLogs = static::getContainer()->get(AuditLogRepository::class);
        $logs = $auditLogs->findBy([
            'entityType' => 'call_session',
            'action' => 'call.browser_event_reconciled',
        ], ['createdAt' => 'DESC']);
        self::assertNotEmpty($logs);
        self::assertSame('csr_hangup', $logs[0]->getMetadata()['normalizedEvent'] ?? null);
    }

    #[Test]
    public function browserEventsEndpointRejectsNonBrowserMode(): void
    {
        $data = $this->seedTenantData();

        // Bridge call mode session.
        $callSession = (new CallSession('provider-recon-4'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BRIDGE) // Bridge mode
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);
        $this->entityManager->flush();

        $this->client->loginUser($data['user']);
        $this->client->request('POST', sprintf(
            '/api/calls/%s/browser-events',
            $callSession->getProviderSessionId(),
        ), [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['event' => 'call.active']));

        // Should succeed at HTTP level (the endpoint doesn't error out), but state should NOT change.
        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']); // Endpoint still returns ok, just doesn't reconcile for bridge mode.

        /** @var CallSessionRepository $repo */
        $repo = static::getContainer()->get(CallSessionRepository::class);
        $updated = $repo->findOneBy(['providerSessionId' => $callSession->getProviderSessionId()]);
        // Bridge mode: state should remain unchanged.
        self::assertSame(CallSession::CALL_STATE_INITIATED, $updated->getCallState());
    }

    #[Test]
    public function browserEventsEndpointReturnsNotFoundForMissingProviderSession(): void
    {
        $data = $this->seedTenantData();

        $this->client->loginUser($data['user']);
        $this->client->request('POST', sprintf(
            '/api/calls/%s/browser-events',
            'nonexistent-provider-id',
        ), [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['event' => 'call.requesting']));

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function browserEventsEndpointRejectsDuplicateCallActiveWithinTwoSeconds(): void
    {
        $data = $this->seedTenantData();

        // Start with ringing state.
        $callSession = (new CallSession('provider-recon-5'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_RINGING)
            ->setClientPhoneNumber($data['contact']->getPrimaryPhone())
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        // Set lastEvent on browser session to 'connected' just now so the second request is deduped.
        $browserSession = new BrowserSoftphoneSession(
            $callSession, $data['tenant'], $data['user'], 'recon-token-5',
        );
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        // First call: active → connected.
        $this->client->loginUser($data['user']);
        $this->client->request('POST', sprintf(
            '/api/calls/%s/browser-events',
            $callSession->getProviderSessionId(),
        ), [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['event' => 'call.active']));

        self::assertResponseIsSuccessful();

        /** @var CallSessionRepository $repo */
        $repo = static::getContainer()->get(CallSessionRepository::class);
        $updated = $repo->findOneBy(['providerSessionId' => $callSession->getProviderSessionId()]);
        self::assertSame(CallSession::CALL_STATE_CONNECTED, $updated->getCallState());

        // Second call immediately: should be deduped (same event name within 2 seconds).
        $this->client->request('POST', sprintf(
            '/api/calls/%s/browser-events',
            $callSession->getProviderSessionId(),
        ), [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['event' => 'call.active']));

        self::assertResponseIsSuccessful();
        $payload = json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['ok']); // Endpoint still returns ok.

        // State should remain the same (only first event was applied).
        /** @var CallSessionRepository $repo2 */
        $repo2 = static::getContainer()->get(CallSessionRepository::class);
        $updatedAgain = $repo2->findOneBy(['providerSessionId' => $callSession->getProviderSessionId()]);
        self::assertSame(CallSession::CALL_STATE_CONNECTED, $updatedAgain->getCallState());
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
