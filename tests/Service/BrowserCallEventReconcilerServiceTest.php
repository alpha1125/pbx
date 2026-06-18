<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Entity\CallSession;
use App\Service\BrowserCallEventReconcilerService;
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
        $testee = new class extends BrowserCallEventReconcilerService {
            public function __construct() {}

            public function exposeNormalize(string $event, array $meta): ?array
            {
                $ref = new \ReflectionMethod(parent::class, 'normalizeBrowserEvent');
                $ref->setAccessible(true);

                return $ref->invoke($this, $event, $meta);
            }
        };

        // Non-call events return null.
        self::assertNull($testee->exposeNormalize('sdk_connecting', []));
        self::assertNull($testee->exposeNormalize('sdk_ready', []));
        self::assertNull($testee->exposeNormalize('sdk_disconnected', []));
        self::assertNull($testee->exposeNormalize('mic_denied', []));
        self::assertNull($testee->exposeNormalize('unknown_event', []));

        // call.requesting -> dialing.
        $n = $testee->exposeNormalize('call.requesting', []);
        self::assertNotNull($n);
        self::assertSame('dialing', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_INITIATED, $n['callState']);

        // call.ringing -> ringing.
        $n = $testee->exposeNormalize('call.ringing', []);
        self::assertNotNull($n);
        self::assertSame('ringing', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_RINGING, $n['callState']);

        // call.active -> connected.
        $n = $testee->exposeNormalize('call.active', []);
        self::assertNotNull($n);
        self::assertSame('connected', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_CONNECTED, $n['callState']);

        // call.hangup -> csr_hangup.
        $n = $testee->exposeNormalize('call.hangup', []);
        self::assertNotNull($n);
        self::assertSame('csr_hangup', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_COMPLETED, $n['callState']);

        // call.failed (default) -> failed.
        $n = $testee->exposeNormalize('call.failed', ['errorCode' => 'no_answer']);
        self::assertNotNull($n);
        self::assertSame('failed', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_FAILED, $n['callState']);

        // call.failed (timeout) -> timed_out.
        $n = $testee->exposeNormalize('call.failed', ['errorCode' => 'timeout']);
        self::assertNotNull($n);
        self::assertSame('timed_out', $n['normalizedEvent']);
        self::assertSame(CallSession::CALL_STATE_FAILED, $n['callState']);
    }
}
