<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TelnyxCallControlService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelnyxCallControlServiceTest extends TestCase
{
    public function testStartRecordingUsesDualChannelWav(): void
    {
        $request = null;
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$request): MockResponse {
                $request = [$method, $url, json_decode($options['body'], true)];

                return new MockResponse('{}', ['http_code' => 200]);
            },
        );

        (new TelnyxCallControlService($client, new NullLogger(), 'test-key'))
            ->startRecording('control-id', [
                'format' => 'wav',
                'channels' => 'dual',
                'recording_track' => 'both',
            ]);

        self::assertSame('POST', $request[0]);
        self::assertStringEndsWith('/calls/control-id/actions/record_start', $request[1]);
        self::assertSame([
            'format' => 'wav',
            'channels' => 'dual',
            'recording_track' => 'both',
        ], $request[2]);
    }

    public function testStartTranscriptionUsesDedicatedAction(): void
    {
        $request = null;
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$request): MockResponse {
                $request = [$method, $url, json_decode($options['body'], true)];

                return new MockResponse('{}', ['http_code' => 200]);
            },
        );

        (new TelnyxCallControlService($client, new NullLogger(), 'test-key'))
            ->startTranscription('control-id', [
                'transcription_engine' => 'Telnyx',
                'transcription_tracks' => 'both',
            ]);

        self::assertSame('POST', $request[0]);
        self::assertStringEndsWith('/calls/control-id/actions/transcription_start', $request[1]);
        self::assertSame([
            'transcription_engine' => 'Telnyx',
            'transcription_tracks' => 'both',
        ], $request[2]);
    }
}
