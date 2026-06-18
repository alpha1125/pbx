<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TelnyxWebRtcProvisioningService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelnyxWebRtcProvisioningServiceTest extends TestCase
{
    public function testGetOrCreateTelephonyCredentialReusesConfiguredCredential(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse(json_encode([
                'data' => [
                    'id' => 'credential-1',
                    'name' => 'csr-browser-poc',
                    'resource_id' => 'connection:2985198995766249199',
                    'sip_username' => 'hidden-user',
                    'sip_password' => 'hidden-password',
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $service = new TelnyxWebRtcProvisioningService(
            $httpClient,
            new NullLogger(),
            'api-key',
            '2985198995766249199',
            'credential-1',
        );

        $credential = $service->getOrCreateTelephonyCredential('csr-browser-poc');

        self::assertSame('credential-1', $credential->id);
        self::assertSame('csr-browser-poc', $credential->name);
        self::assertSame('connection:2985198995766249199', $credential->resourceId);
        self::assertFalse(property_exists($credential, 'sipPassword'));
        self::assertCount(1, $requests);
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame('https://api.telnyx.com/v2/telephony_credentials/credential-1', $requests[0]['url']);
    }

    public function testGetOrCreateTelephonyCredentialCreatesCredentialWhenMissing(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            if ('GET' === $method) {
                return new MockResponse(json_encode(['data' => []], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            return new MockResponse(json_encode([
                'data' => [
                    'id' => 'credential-new',
                    'name' => 'csr-browser-poc',
                    'resource_id' => 'connection:2985198995766249199',
                    'sip_username' => 'hidden-user',
                    'sip_password' => 'hidden-password',
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });

        $service = new TelnyxWebRtcProvisioningService(
            $httpClient,
            new NullLogger(),
            'api-key',
            '2985198995766249199',
            '',
        );

        $credential = $service->getOrCreateTelephonyCredential('csr-browser-poc');

        self::assertSame('credential-new', $credential->id);
        self::assertSame('csr-browser-poc', $credential->name);
        self::assertSame('connection:2985198995766249199', $credential->resourceId);
        self::assertCount(2, $requests);
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame('POST', $requests[1]['method']);
        self::assertSame('https://api.telnyx.com/v2/telephony_credentials', $requests[1]['url']);
        self::assertSame([
            'name' => 'csr-browser-poc',
            'connection_id' => '2985198995766249199',
        ], json_decode((string) $requests[1]['options']['body'], true, flags: JSON_THROW_ON_ERROR));
    }
}
