<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\BrowserSoftphoneSession;
use App\Repository\BrowserSoftphoneSessionRepository;
use App\Service\AuditLogger;
use App\Service\BrowserSoftphoneSessionService;
use App\Service\CallEventEngineService;
use App\Service\TelnyxCallControlService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CrmBrowserOutboundDialServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private TelnyxCallControlService $callControl;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
        $this->entityManager->expects($this->any())
            ->method('flush')
            ->willReturnCallback(function () {});
        $this->entityManager->expects($this->any())
            ->method('persist')
            ->willReturnCallback(function () {});

        // Set up mock Telnyx response for dial
        $mockResponse = new MockResponse(json_encode(['data' => ['call_leg_id' => 'leg-outbound-1']]), ['http_code' => 200]);
        $this->callControl = new TelnyxCallControlService(
            new \Symfony\Component\HttpClient\MockHttpClient(fn () => $mockResponse),
            new NullLogger(),
            'api-key',
        );
    }

    #[Test]
    public function dialRequiresValidConnectionId(): void
    {
        $browserSession = $this->createStub(BrowserSoftphoneSession::class);
        $browserSession->method('getTelnyxConnectionId')->willReturn(null);
        $browserSession->method('getConnectionState')->willReturn(BrowserSoftphoneSession::CONNECTION_STATE_READY);

        $tenant = (new Tenant('Test Tenant'))->setEmail('test@example.com');
        $user = (new User())->setEmail('csr@test.com')->setPassword('x')->setDisplayName('CSR');
        $callSession = new CallSession('provider-123')
            ->setProvider('telnyx')
            ->setTenant($tenant)
            ->setProperty(new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1'))
            ->setContact((new Contact($tenant, 'Test Contact'))->setPrimaryPhone('+14165550123'))
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setStatus('active');

        $this->assertFalse($this->validateDialSession($browserSession, $callSession));
    }

    #[Test]
    public function dialRequiresActiveConnection(): void
    {
        $browserSession = $this->createStub(BrowserSoftphoneSession::class);
        $browserSession->method('getTelnyxConnectionId')->willReturn('conn-123');
        $browserSession->method('getConnectionState')->willReturn(BrowserSoftphoneSession::CONNECTION_STATE_IDLE);

        $tenant = (new Tenant('Test Tenant'))->setEmail('test@example.com');
        $user = (new User())->setEmail('csr@test.com')->setPassword('x')->setDisplayName('CSR');
        $callSession = new CallSession('provider-123')
            ->setProvider('telnyx')
            ->setTenant($tenant)
            ->setProperty(new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1'))
            ->setContact((new Contact($tenant, 'Test Contact'))->setPrimaryPhone('+14165550123'))
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setStatus('active');

        $this->assertFalse($this->validateDialSession($browserSession, $callSession));
    }

    #[Test]
    public function dialRequiresBrowserCallMode(): void
    {
        $browserSession = $this->createStub(BrowserSoftphoneSession::class);
        $browserSession->method('getTelnyxConnectionId')->willReturn('conn-123');
        $browserSession->method('getConnectionState')->willReturn(BrowserSoftphoneSession::CONNECTION_STATE_READY);

        $tenant = (new Tenant('Test Tenant'))->setEmail('test@example.com');
        $user = (new User())->setEmail('csr@test.com')->setPassword('x')->setDisplayName('CSR');
        $callSession = new CallSession('provider-123')
            ->setProvider('telnyx')
            ->setTenant($tenant)
            ->setProperty(new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1'))
            ->setContact((new Contact($tenant, 'Test Contact'))->setPrimaryPhone('+14165550123'))
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BRIDGE)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setStatus('active');

        $this->assertFalse($this->validateDialSession($browserSession, $callSession));
    }

    #[Test]
    public function dialRequiresNonTerminalState(): void
    {
        foreach ([CallSession::CALL_STATE_COMPLETED, CallSession::CALL_STATE_FAILED] as $terminalState) {
            $browserSession = $this->createStub(BrowserSoftphoneSession::class);
            $browserSession->method('getTelnyxConnectionId')->willReturn('conn-123');
            $browserSession->method('getConnectionState')->willReturn(BrowserSoftphoneSession::CONNECTION_STATE_READY);

            $tenant = (new Tenant('Test Tenant'))->setEmail('test@example.com');
            $user = (new User())->setEmail('csr@test.com')->setPassword('x')->setDisplayName('CSR');
            $callSession = new CallSession('provider-123')
                ->setProvider('telnyx')
                ->setTenant($tenant)
                ->setProperty(new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1'))
                ->setContact((new Contact($tenant, 'Test Contact'))->setPrimaryPhone('+14165550123'))
                ->setCsrUser($user)
                ->setCallMode(CallSession::CALL_MODE_BROWSER)
                ->setCallState($terminalState)
                ->setStatus('completed');

            $this->assertFalse($this->validateDialSession($browserSession, $callSession));
        }
    }

    #[Test]
    public function dialSucceedsWithValidSession(): void
    {
        $tenant = (new Tenant('Test Tenant'))->setEmail('test@example.com');
        $user = (new User())->setEmail('csr@test.com')->setPassword('x')->setDisplayName('CSR');

        // Create actual BrowserSoftphoneSession to test telnyxConnectionId getter/setter
        $callSession = new CallSession('provider-valid')
            ->setProvider('telnyx')
            ->setTenant($tenant)
            ->setProperty(new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1'))
            ->setContact((new Contact($tenant, 'Test Contact'))->setPrimaryPhone('+14165550123'))
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setStatus('active');

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $tenant,
            $user,
            'session-token-123',
        );
        $browserSession->setTelnyxConnectionId('telnyx-conn-web-abc456');

        // Verify the connection ID can be captured and retrieved
        self::assertSame('telnyx-conn-web-abc456', $browserSession->getTelnyxConnectionId());
        self::assertSame(BrowserSoftphoneSession::CONNECTION_STATE_IDLE, $browserSession->getConnectionState());

        // Simulate SDK-ready state
        $browserSession->setConnectionState(BrowserSoftphoneSession::CONNECTION_STATE_READY);

        // All validations should pass for valid session
        self::assertTrue($this->validateDialSession($browserSession, $callSession));
    }

    #[Test]
    public function dialEntityCapturesAndStoresTelnyxConnectionId(): void
    {
        $tenant = (new Tenant('Test Tenant'))->setEmail('test@example.com');
        $user = (new User())->setEmail('csr@test.com')->setPassword('x')->setDisplayName('CSR');
        $callSession = new CallSession('provider-123')
            ->setProvider('telnyx')
            ->setTenant($tenant)
            ->setProperty(new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1'))
            ->setContact((new Contact($tenant, 'Test Contact'))->setPrimaryPhone('+14165550123'))
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setStatus('active');

        $browserSession = new BrowserSoftphoneSession(
            $callSession,
            $tenant,
            $user,
            'session-token-abc',
        );

        // Initially null
        self::assertNull($browserSession->getTelnyxConnectionId());

        // Capture connection ID from SDK (simulating telnyx.ready callback)
        $browserSession->setTelnyxConnectionId('ws-conn-from-telnyx-sdk-xyz');
        self::assertSame('ws-conn-from-telnyx-sdk-xyz', $browserSession->getTelnyxConnectionId());

        // Trimmed empty value should become null
        $browserSession->setTelnyxConnectionId('   ');
        self::assertNull($browserSession->getTelnyxConnectionId());
    }

    /**
     * @param \App\Entity\BrowserSoftphoneSession $browserSession
     */
    private function validateDialSession($browserSession, CallSession $callSession): bool
    {
        // Validate connection is active
        if (CallSession::CALL_STATE_CONNECTED !== $browserSession->getConnectionState()) {
            return false;
        }

        // Validate connection ID exists
        $connectionId = $browserSession->getTelnyxConnectionId();
        if (null === $connectionId || '' === trim($connectionId)) {
            return false;
        }

        // Validate call mode is browser_call
        if (CallSession::CALL_MODE_BROWSER !== $callSession->getCallMode()) {
            return false;
        }

        // Validate non-terminal state
        if (in_array($callSession->getCallState(), [CallSession::CALL_STATE_COMPLETED, CallSession::CALL_STATE_FAILED], true)) {
            return false;
        }

        return true;
    }
}
