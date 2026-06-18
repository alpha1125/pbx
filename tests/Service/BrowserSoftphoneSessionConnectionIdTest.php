<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BrowserSoftphoneSession;
use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 9I.1/9I.3 — Persist Telnyx WebRTC connection id and browser call control id.
 * Service test proving BrowserSoftphoneSession.telnyxConnectionId and
 * BrowserSoftphoneSession.telnyxCallControlId field behavior
 * (setter/getter/trim/null) without requiring a database.
 */
final class BrowserSoftphoneSessionConnectionIdTest extends TestCase
{
    /** @return BrowserSoftphoneSession */
    private function seedBrowserSession(): BrowserSoftphoneSession
    {
        $tenant = (new Tenant('Test Tenant'))->setEmail('test@example.com');
        $user = (new User())->setEmail('csr@test.com')->setPassword('unused')->setDisplayName('CSR');
        $property = new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1');
        $contact = (new Contact($tenant, 'Contact'))->setPrimaryPhone('+14165550123');

        $callSession = (new CallSession('provider-test-cid'))
            ->setProvider('telnyx')
            ->setTenant($tenant)
            ->setProperty($property)
            ->setContact($contact)
            ->setCsrUser($user)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setStatus('active')
            ->touch();

        return new BrowserSoftphoneSession(
            $callSession,
            $tenant,
            $user,
            'session-token-test-cid',
        );
    }

    #[Test]
    public function connectionIdFieldDefaultsToNull(): void
    {
        $session = $this->seedBrowserSession();
        self::assertNull($session->getTelnyxConnectionId());
    }

    #[Test]
    public function connectionIdSetterAcceptsAndRetrievesValue(): void
    {
        $session = $this->seedBrowserSession();
        $session->setTelnyxConnectionId('webrtc-conn-from-sdk-abc123');
        self::assertSame('webrtc-conn-from-sdk-abc123', $session->getTelnyxConnectionId());
    }

    #[Test]
    public function connectionIdSetterTrimsWhitespace(): void
    {
        $session = $this->seedBrowserSession();
        $session->setTelnyxConnectionId('  webrtc-conn-trimmed  ');
        self::assertSame('webrtc-conn-trimmed', $session->getTelnyxConnectionId());
    }

    #[Test]
    public function connectionIdSetterConvertsWhitespacedStringToNull(): void
    {
        $session = $this->seedBrowserSession();
        $session->setTelnyxConnectionId('   ');
        self::assertNull($session->getTelnyxConnectionId());
    }

    #[Test]
    public function connectionIdCanBeNull(): void
    {
        $session = $this->seedBrowserSession();
        $session->setTelnyxConnectionId(null);
        self::assertNull($session->getTelnyxConnectionId());
    }

    #[Test]
    public function callControlIdFieldDefaultsToNull(): void
    {
        $session = $this->seedBrowserSession();
        self::assertNull($session->getTelnyxCallControlId());
    }

    #[Test]
    public function callControlIdSetterTrimsWhitespace(): void
    {
        $session = $this->seedBrowserSession();
        $session->setTelnyxCallControlId('  call-control-123  ');
        self::assertSame('call-control-123', $session->getTelnyxCallControlId());
    }
}
