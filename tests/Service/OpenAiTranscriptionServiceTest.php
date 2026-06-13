<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\OpenAiTranscriptionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiTranscriptionServiceTest extends TestCase
{
    public function testTranscribeAudioFileUsesJsonForGpt4oMiniTranscribe(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pbx-audio-');
        file_put_contents($tmp, 'audio');
        $captured = [];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured['method'] = $method;
                $captured['url'] = $url;
                $captured['headers'] = $options['headers'];
                $body = $options['body'];
                if ($body instanceof \Closure) {
                    $body = $body();
                }
                $captured['body'] = is_string($body)
                    ? $body
                    : implode('', is_array($body) ? $body : iterator_to_array($body));

                return new MockResponse(json_encode([
                    'text' => 'hello world',
                    'language' => 'en',
                    'duration' => 12.4,
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            },
        );

        try {
            $result = (new OpenAiTranscriptionService($client, 'test-key', 'gpt-4o-mini-transcribe'))
                ->transcribeAudioFile($tmp, 'audio.wav');
        } finally {
            @unlink($tmp);
        }

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.openai.com/v1/audio/transcriptions', $captured['url']);
        self::assertContains('Authorization: Bearer test-key', $captured['headers']);
        self::assertSame('hello world', $result['text']);
        self::assertSame('en', $result['language']);
        self::assertSame(12, $result['durationSeconds']);
    }
}
