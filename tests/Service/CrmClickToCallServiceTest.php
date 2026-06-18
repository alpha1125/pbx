<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\ClickToCallRequest;
use App\Service\AuditLogger;
use App\Service\ClickToCallService;
use App\Service\CrmClickToCallService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class CrmClickToCallServiceTest extends TestCase
{
    public function testStartSeedsUnifiedOutboundCallFieldsOnBridgeCallSession(): void
    {
        $tenant = new Tenant('Tenant One');
        $property = new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1');
        $contact = (new Contact($tenant, 'Tenant Contact'))->setPrimaryPhone('+14165550123');
        $user = (new User())
            ->setEmail('csr@example.com')
            ->setPassword('unused')
            ->setDisplayName('CSR Agent')
            ->setCellPhone('+14165550111');
        $session = (new CallSession('session-1'))
            ->setStatus('initiated');
        $request = (new ClickToCallRequest('+14165550111', '+14165550123', '+14165550111', 'connection-1'))
            ->setCallSession($session);

        $clickToCall = $this->createMock(ClickToCallService::class);
        $clickToCall->expects(self::once())
            ->method('start')
            ->with('+14165550123', 'Tenant Contact', '+14165550111')
            ->willReturn($request);

        $security = $this->createMock(Security::class);
        $security->expects(self::once())
            ->method('getUser')
            ->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
            $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                $tenant,
                'call_session',
                'new',
                'call.bridge_call_started',
                null,
                self::callback(static fn (?array $afterData): bool => is_array($afterData) && array_key_exists('status', $afterData) && array_key_exists('flowType', $afterData)),
                self::callback(static fn (?array $metadata): bool => is_array($metadata) && array_key_exists('propertyId', $metadata) && array_key_exists('contactId', $metadata)),
            );

        $service = new CrmClickToCallService($clickToCall, $entityManager, $security, $auditLogger);

        $result = $service->start($property, $contact);

        self::assertSame($session, $result);
        self::assertSame($tenant, $session->getTenant());
        self::assertSame($property, $session->getProperty());
        self::assertSame($contact, $session->getContact());
        self::assertSame(CallSession::FLOW_TYPE_CLICK_TO_CALL, $session->getFlowType());
        self::assertSame(CallSession::CALL_MODE_BRIDGE, $session->getCallMode());
        self::assertSame(CallSession::CALL_STATE_INITIATED, $session->getCallState());
        self::assertSame(CallSession::RECORDING_STATE_INACTIVE, $session->getRecordingState());
        self::assertSame(CallSession::TRANSCRIPTION_STATE_INACTIVE, $session->getTranscriptionState());
        self::assertSame('+14165550123', $session->getClientPhoneNumber());
        self::assertSame($user, $session->getCsrUser());
    }
}
