<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class CallSessionModelTest extends TestCase
{
    public function testUnifiedOutboundCallFieldsRoundTrip(): void
    {
        $tenant = new Tenant('Tenant One');
        $property = new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1');
        $contact = new Contact($tenant, 'Tenant Contact');
        $user = (new User())
            ->setEmail('csr@example.com')
            ->setPassword('unused')
            ->setDisplayName('CSR Agent');

        $session = new CallSession('session-1');
        self::assertNull($session->getCallMode());
        self::assertNull($session->getCallState());
        self::assertNull($session->getRecordingState());
        self::assertNull($session->getTranscriptionState());
        self::assertNull($session->getClientPhoneNumber());

        $session
            ->setTenant($tenant)
            ->setCsrUser($user)
            ->setProperty($property)
            ->setContact($contact)
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_RINGING)
            ->setRecordingState(CallSession::RECORDING_STATE_CONSENT_PLAYING)
            ->setTranscriptionState(CallSession::TRANSCRIPTION_STATE_INACTIVE)
            ->setClientPhoneNumber('+14165550123');

        self::assertSame($tenant, $session->getTenant());
        self::assertSame($user, $session->getCsrUser());
        self::assertSame($property, $session->getProperty());
        self::assertSame($contact, $session->getContact());
        self::assertSame(CallSession::CALL_MODE_BROWSER, $session->getCallMode());
        self::assertSame(CallSession::CALL_STATE_RINGING, $session->getCallState());
        self::assertSame(CallSession::RECORDING_STATE_CONSENT_PLAYING, $session->getRecordingState());
        self::assertSame(CallSession::TRANSCRIPTION_STATE_INACTIVE, $session->getTranscriptionState());
        self::assertSame('+14165550123', $session->getClientPhoneNumber());
    }
}
