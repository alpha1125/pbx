<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PocBrowserSoftphoneTranscriptMergeService;
use PHPUnit\Framework\TestCase;

final class PocBrowserSoftphoneTranscriptMergeServiceTest extends TestCase
{
    public function testFinalSegmentReplacesMatchingInterimSegment(): void
    {
        $service = new PocBrowserSoftphoneTranscriptMergeService();

        $result = $service->mergeSegments([
            [
                'id' => 7,
                'sequence' => 7,
                'speaker' => 'customer',
                'text' => 'I need the system back',
                'occurredAt' => '2026-06-18T12:00:00+00:00',
                'displayTime' => '12:00 PM',
                'isFinal' => false,
                'sourceEventId' => 'evt-7',
                'fingerprint' => 'interim-7',
            ],
        ], [
            'id' => 8,
            'sequence' => 8,
            'speaker' => 'customer',
            'text' => 'I need the system back online.',
            'occurredAt' => '2026-06-18T12:00:00+00:00',
            'displayTime' => '12:00 PM',
            'isFinal' => true,
            'sourceEventId' => 'evt-7',
            'fingerprint' => 'final-7',
        ]);

        self::assertTrue($result['changed']);
        self::assertFalse($result['added']);
        self::assertCount(1, $result['segments']);
        self::assertTrue($result['segment']['isFinal']);
        self::assertSame(7, $result['segment']['id']);
        self::assertSame('I need the system back online.', $result['segment']['text']);
        self::assertSame('evt-7', $result['segment']['sourceEventId']);
        self::assertSame('final-7', $result['segment']['fingerprint']);
    }

    public function testInterimSegmentDoesNotReplaceExistingFinalSegment(): void
    {
        $service = new PocBrowserSoftphoneTranscriptMergeService();

        $result = $service->mergeSegments([
            [
                'id' => 7,
                'sequence' => 7,
                'speaker' => 'customer',
                'text' => 'I need the system back online.',
                'occurredAt' => '2026-06-18T12:00:00+00:00',
                'displayTime' => '12:00 PM',
                'isFinal' => true,
                'sourceEventId' => 'evt-7',
                'fingerprint' => 'final-7',
            ],
        ], [
            'id' => 8,
            'sequence' => 8,
            'speaker' => 'customer',
            'text' => 'I need the system back',
            'occurredAt' => '2026-06-18T12:00:00+00:00',
            'displayTime' => '12:00 PM',
            'isFinal' => false,
            'sourceEventId' => 'evt-7',
            'fingerprint' => 'interim-7',
        ]);

        self::assertFalse($result['changed']);
        self::assertFalse($result['added']);
        self::assertCount(1, $result['segments']);
        self::assertTrue($result['segment']['isFinal']);
        self::assertSame('I need the system back online.', $result['segment']['text']);
        self::assertSame('final-7', $result['segment']['fingerprint']);
    }
}
