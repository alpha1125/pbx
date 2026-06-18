<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BrowserSoftphoneSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Entity\CallSession;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\BrowserCallEventReconcilerService;
use App\Repository\BrowserSoftphoneSessionRepository;
use App\Repository\CallSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class BrowserCallEventReconcilerServiceTest extends TestCase
{
    #[Test]
    public function serviceClassExistsAndHasExpectedMethods(): void
    {
        $reflection = new \ReflectionClass(BrowserCallEventReconcilerService::class);
        self::assertTrue($reflection->hasMethod('reconcile'));
        self::assertTrue($reflection->hasMethod('reconcileWebhook'));

        $method = $reflection->getMethod('reconcile');
        self::assertSame(5, $method->getNumberOfParameters());
    }

    #[Test]
    public function serviceInstantiatesWithAllRequiredDependencies(): void
    {
        $reflection = new \ReflectionClass(BrowserCallEventReconcilerService::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        $params = $constructor->getParameters();
        self::assertCount(7, $params);

        // Verify the normalize method exists (for testing event mappings).
        self::assertTrue($reflection->hasMethod('normalizeBrowserEvent'));
    }

    /**
     * Test normalizeBrowserEvent event mappings via a test subclass.
     */
    #[Test]
    public function normalizeEventMappingsAreCorrect(): void
    {
        $reflection = new \ReflectionClass(BrowserCallEventReconcilerService::class);
        $testee = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('normalizeBrowserEvent');
        $method->setAccessible(true);

        $normalize = static fn (string $event, array $meta): ?array => $method->invoke($testee, $event, $meta);

        // Non-call events return null.
        self::assertNull($normalize('sdk_connecting', []));
        self::assertNull($normalize('sdk_ready', []));
        self::assertNull($normalize('sdk_disconnected', []));
        self::assertNull($normalize('mic_denied', []));
        self::assertNull($normalize('unknown_event', []));

        // call.requesting -> dialing.
        $n = $normalize('call.requesting', []);
        self::assertNotNull($n);
        self::assertSame('dialing', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_INITIATED, $n['callState']);

        // call.ringing -> ringing.
        $n = $normalize('call.ringing', []);
        self::assertNotNull($n);
        self::assertSame('ringing', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_RINGING, $n['callState']);

        // call.active -> connected.
        $n = $normalize('call.active', []);
        self::assertNotNull($n);
        self::assertSame('connected', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_CONNECTED, $n['callState']);

        // call.hangup -> csr_hangup.
        $n = $normalize('call.hangup', []);
        self::assertNotNull($n);
        self::assertSame('csr_hangup', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_COMPLETED, $n['callState']);

        // call.failed (default) -> failed.
        $n = $normalize('call.failed', ['errorCode' => 'no_answer']);
        self::assertNotNull($n);
        self::assertSame('failed', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_FAILED, $n['callState']);

        // call.failed (timeout) -> timed_out.
        $n = $normalize('call.failed', ['errorCode' => 'timeout']);
        self::assertNotNull($n);
        self::assertSame('timed_out', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_FAILED, $n['callState']);
    }

    #[Test]
    public function staleBrowserEventsDoNotDowngradeTheAuthoritativeCallState(): void
    {
        $reflection = new \ReflectionClass(BrowserCallEventReconcilerService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isStateAcceptable');
        $method->setAccessible(true);

        $session = (new CallSession('provider-stale-event'))
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_COMPLETED)
            ->setStatus('completed');

        self::assertFalse($method->invoke($service, $session, CallSession::CALL_STATE_RINGING));
        self::assertFalse($method->invoke($service, $session, CallSession::CALL_STATE_COMPLETED));

        $session->setCallState(CallSession::CALL_STATE_INITIATED);
        self::assertTrue($method->invoke($service, $session, CallSession::CALL_STATE_RINGING));
    }
}
