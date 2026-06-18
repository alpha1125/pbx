<?php

declare(strict_types=1);

namespace App\Tests\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use App\Service\TelnyxCallControlService;

final class TelnyxCallControlEventsTest extends TestCase
{
    #[Test]
    public function playDtmfSendsCorrectAction(): void
    {
        $captured = new \stdClass();
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($captured): MockResponse {
            $captured->path = parse_url($url, PHP_URL_PATH) ?? '';
            return new MockResponse(json_encode(['data' => ['success' => true]]), ['http_code' => 200]);
        });

        $service = new TelnyxCallControlService(
            $httpClient,
            new NullLogger(),
            'api-key',
        );

        $result = $service->playDtmf('cc-dtmf-test', '1*9#');

        self::assertIsArray($result);
        self::assertStringContainsString('/play_dtmf', $captured->path);
    }

    #[Test]
    public function playDtmfReturnsNullForEmptyTones(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('{}', ['http_code' => 200]));
        $service = new TelnyxCallControlService($httpClient, new NullLogger(), 'api-key');

        self::assertNull($service->playDtmf('cc-123', ''));
    }

    #[Test]
    public function muteSendsPauseAction(): void
    {
        $captured = new \stdClass();
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($captured): MockResponse {
            $captured->path = parse_url($url, PHP_URL_PATH) ?? '';
            return new MockResponse(json_encode(['data' => ['success' => true]]), ['http_code' => 200]);
        });

        $service = new TelnyxCallControlService($httpClient, new NullLogger(), 'api-key');
        $result = $service->mute('cc-mute-test', true);

        self::assertIsArray($result);
        self::assertStringContainsString('/pause', $captured->path);
    }

    #[Test]
    public function unmuteSendsResumeAction(): void
    {
        $captured = new \stdClass();
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($captured): MockResponse {
            $captured->path = parse_url($url, PHP_URL_PATH) ?? '';
            return new MockResponse(json_encode(['data' => ['success' => true]]), ['http_code' => 200]);
        });

        $service = new TelnyxCallControlService($httpClient, new NullLogger(), 'api-key');
        $result = $service->mute('cc-unmute-test', false);

        self::assertIsArray($result);
        self::assertStringContainsString('/resume', $captured->path);
    }

    #[Test]
    public function hangupSendsHangupAction(): void
    {
        $captured = new \stdClass();
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($captured): MockResponse {
            $captured->path = parse_url($url, PHP_URL_PATH) ?? '';
            return new MockResponse(json_encode(['data' => ['success' => true]]), ['http_code' => 200]);
        });

        $service = new TelnyxCallControlService($httpClient, new NullLogger(), 'api-key');
        $result = $service->hangup('cc-hangup-test');

        self::assertIsArray($result);
        self::assertStringContainsString('/hangup', $captured->path);
    }
}
