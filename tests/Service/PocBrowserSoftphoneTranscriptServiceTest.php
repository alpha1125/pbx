<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PocBrowserSoftphoneTranscriptService;
use PHPUnit\Framework\TestCase;

final class PocBrowserSoftphoneTranscriptServiceTest extends TestCase
{
    public function testTopicGenerationAndPublishPayloadUseThePocTopic(): void
    {
        $service = new PocBrowserSoftphoneTranscriptService();
        $segment = [
            'id' => 1,
            'sequence' => 1,
            'speaker' => 'customer',
            'text' => 'Hello',
            'occurredAt' => '2026-06-18T12:00:00+00:00',
            'displayTime' => '12:00 PM',
            'isFinal' => true,
            'sourceEventId' => 'evt-1',
            'fingerprint' => 'fingerprint-1',
        ];

        self::assertSame('/poc/browser-softphone/call-123/transcript', $service->topicForCallSession('call-123'));
        self::assertSame('/api/poc/browser-softphone/call-123/transcript/stream', $service->streamUrlForCallSession('call-123'));
        self::assertSame([
            'topic' => '/poc/browser-softphone/call-123/transcript',
            'callSessionId' => 'call-123',
            'segment' => $segment,
        ], $service->buildPublishPayload('call-123', $segment));
    }

    public function testRecordSegmentDeduplicatesExactTranscriptEvents(): void
    {
        $service = new PocBrowserSoftphoneTranscriptService();
        $callSessionId = 'call-dup-'.bin2hex(random_bytes(4));
        $occurredAt = new \DateTimeImmutable('2026-06-18T12:00:00+00:00');

        $first = $service->recordSegment($callSessionId, 'customer', 'The boiler is making noise.', $occurredAt, true, 'evt-42');
        $second = $service->recordSegment($callSessionId, 'customer', 'The boiler is making noise.', $occurredAt, true, 'evt-42');

        self::assertFalse($first['deduplicated']);
        self::assertTrue($second['deduplicated']);
        self::assertSame($first['segment']['id'], $second['segment']['id']);
        self::assertSame($first['segment']['fingerprint'], $second['segment']['fingerprint']);

        $stored = $service->fetchSince($callSessionId, 0);
        self::assertCount(1, $stored['segments']);
        self::assertSame(1, $stored['cursor']);
        self::assertSame('customer', $stored['segments'][0]['speaker']);
        self::assertSame('The boiler is making noise.', $stored['segments'][0]['text']);
    }

    public function testRecordSegmentUpdatesAnInterimSegmentWhenTheFinalVersionArrives(): void
    {
        $service = new PocBrowserSoftphoneTranscriptService();
        $callSessionId = 'call-final-'.bin2hex(random_bytes(4));
        $occurredAt = new \DateTimeImmutable('2026-06-18T12:00:00+00:00');

        $interim = $service->recordSegment($callSessionId, 'customer', 'I need the system back', $occurredAt, false, 'evt-77');
        $final = $service->recordSegment($callSessionId, 'customer', 'I need the system back online.', $occurredAt, true, 'evt-77');

        self::assertFalse($interim['deduplicated']);
        self::assertFalse($final['deduplicated']);
        self::assertSame($interim['segment']['id'], $final['segment']['id']);
        self::assertFalse($interim['segment']['isFinal']);
        self::assertTrue($final['segment']['isFinal']);
        self::assertSame('I need the system back online.', $final['segment']['text']);

        $stored = $service->fetchSince($callSessionId, 0);
        self::assertCount(1, $stored['segments']);
        self::assertSame(1, $stored['cursor']);
        self::assertTrue($stored['segments'][0]['isFinal']);
        self::assertSame('I need the system back online.', $stored['segments'][0]['text']);
    }

    public function testCallControlIdsResolveToTheStableTranscriptSession(): void
    {
        $service = new PocBrowserSoftphoneTranscriptService();
        $callSessionId = 'poc-call-'.bin2hex(random_bytes(4));
        $callControlId = 'control-'.bin2hex(random_bytes(4));

        $service->registerCallControlId($callSessionId, $callControlId);

        self::assertSame($callSessionId, $service->resolveCallSessionIdForCallControlId($callControlId));
        self::assertNull($service->resolveCallSessionIdForCallControlId('control-missing'));
    }
}
