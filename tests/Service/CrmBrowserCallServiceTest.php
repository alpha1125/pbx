<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\ClientStateService;
use App\Service\CrmBrowserCallService;
use App\Service\TelnyxCallControlService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class CrmBrowserCallServiceTest extends TestCase
{
    public function testStartSeedsBrowserCallFieldsAndDialsCustomer(): void
    {
        $tenant = new Tenant('Tenant One');
        $property = new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1');
        $contact = (new Contact($tenant, 'Tenant Contact'))->setPrimaryPhone('+14165550123');
        $user = (new User())
            ->setEmail('csr@example.com')
            ->setPassword('unused')
            ->setDisplayName('CSR Agent');

        $dialedOptions = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())->method('getStatusCode')->willReturn(200);
        $response->expects(self::once())->method('getContent')->with(false)->willReturn('{"data":{"call_leg_id":"leg-1"}}');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->willReturnCallback(static function (
                string $method,
                string $url,
                array $options = [],
            ) use (&$dialedOptions, $response): ResponseInterface {
                self::assertSame('POST', $method);
                self::assertSame('https://api.telnyx.com/v2/calls', $url);
                $dialedOptions = $options;

                return $response;
            });

        $callControl = new TelnyxCallControlService(
            $httpClient,
            $this->createStub(LoggerInterface::class),
            'api-key',
        );

        $security = $this->createMock(Security::class);
        $security->expects(self::once())
            ->method('getUser')
            ->willReturn($user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(CallSession::class));
        $entityManager->expects(self::exactly(2))->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                $tenant,
                'call_session',
                'new',
                'call.browser_call_started',
                null,
                self::callback(static fn (?array $afterData): bool => is_array($afterData) && ($afterData['callMode'] ?? null) === CallSession::CALL_MODE_BROWSER),
                self::callback(static fn (?array $metadata): bool => is_array($metadata) && array_key_exists('propertyId', $metadata) && array_key_exists('contactId', $metadata)),
            );

        $service = new CrmBrowserCallService(
            $callControl,
            $entityManager,
            $security,
            $auditLogger,
            new ClientStateService(),
            '+12892079888',
            'connection-1',
        );

        $session = $service->start($property, $contact);

        self::assertSame($tenant, $session->getTenant());
        self::assertSame($property, $session->getProperty());
        self::assertSame($contact, $session->getContact());
        self::assertSame($user, $session->getCsrUser());
        self::assertSame(CallSession::CALL_MODE_BROWSER, $session->getCallMode());
        self::assertSame(CallSession::CALL_STATE_INITIATED, $session->getCallState());
        self::assertSame(CallSession::RECORDING_STATE_INACTIVE, $session->getRecordingState());
        self::assertSame(CallSession::TRANSCRIPTION_STATE_INACTIVE, $session->getTranscriptionState());
        self::assertSame('+14165550123', $session->getClientPhoneNumber());
        self::assertSame('+12892079888', $session->getInboundFrom());
        self::assertSame('+14165550123', $session->getInboundTo());

        self::assertIsArray($dialedOptions);
        self::assertSame([
            'connection_id' => 'connection-1',
            'from' => '+12892079888',
            'to' => '+14165550123',
            'client_state' => $dialedOptions['json']['client_state'] ?? null,
            'timeout_secs' => 45,
            'command_id' => $dialedOptions['json']['command_id'] ?? null,
        ], [
            'connection_id' => $dialedOptions['json']['connection_id'] ?? null,
            'from' => $dialedOptions['json']['from'] ?? null,
            'to' => $dialedOptions['json']['to'] ?? null,
            'client_state' => $dialedOptions['json']['client_state'] ?? null,
            'timeout_secs' => $dialedOptions['json']['timeout_secs'] ?? null,
            'command_id' => $dialedOptions['json']['command_id'] ?? null,
        ]);

        $decodedClientState = (new ClientStateService())->decode((string) ($dialedOptions['json']['client_state'] ?? ''));
        self::assertIsArray($decodedClientState);
        self::assertSame(CallSession::FLOW_TYPE_CLICK_TO_CALL, $decodedClientState['flow']);
        self::assertSame(CallSession::CALL_MODE_BROWSER, $decodedClientState['call_mode']);
        self::assertArrayHasKey('root_call_session_id', $decodedClientState);
        self::assertMatchesRegularExpression('/^local-browser-/', $decodedClientState['root_call_session_id']);
        self::assertStringStartsWith('browser-call:', (string) ($dialedOptions['json']['command_id'] ?? ''));
    }
}
