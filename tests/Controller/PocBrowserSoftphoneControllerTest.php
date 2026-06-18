<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Poc\BrowserSoftphoneController;
use App\Service\TelnyxWebRtcProvisioningService;
use App\Service\TelnyxWebrtcTokenService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class PocBrowserSoftphoneControllerTest extends TestCase
{
    public function testTokenEndpointReturnsTokenAndNumbers(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            if ('GET' === $method) {
                return new MockResponse(json_encode([
                    'data' => [
                        'id' => 'credential-123',
                        'name' => 'csr-browser-poc',
                        'resource_id' => 'connection:2985198995766249199',
                    ],
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            return new MockResponse('header.payload.signature', ['http_code' => 201]);
        });

        $provisioningService = new TelnyxWebRtcProvisioningService(
            $httpClient,
            new NullLogger(),
            'api-key',
            '2985198995766249199',
            'credential-123',
        );
        $tokenService = new TelnyxWebrtcTokenService(
            $httpClient,
            new NullLogger(),
            new \Symfony\Component\Cache\Adapter\ArrayAdapter(),
            'api-key',
            'unused-connection-id',
            'unused-credential-id',
        );

        $controller = new BrowserSoftphoneController(
            $provisioningService,
            $tokenService,
            '+15551231234',
            '+15557654321',
        );

        $response = $controller->token(Request::create('/poc/browser-softphone/token', 'POST'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertSame('header.payload.signature', $payload['token']);
        self::assertSame('+15557654321', $payload['destinationNumber']);
        self::assertSame('+15551231234', $payload['callerNumber']);
        self::assertCount(2, $requests);
    }

    public function testTokenEndpointAllowsDestinationOverride(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            if ('GET' === $method) {
                return new MockResponse(json_encode([
                    'data' => [
                        'id' => 'credential-123',
                        'name' => 'csr-browser-poc',
                        'resource_id' => 'connection:2985198995766249199',
                    ],
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            return new MockResponse('header.payload.signature', ['http_code' => 201]);
        });

        $provisioningService = new TelnyxWebRtcProvisioningService(
            $httpClient,
            new NullLogger(),
            'api-key',
            '2985198995766249199',
            'credential-123',
        );
        $tokenService = new TelnyxWebrtcTokenService(
            $httpClient,
            new NullLogger(),
            new \Symfony\Component\Cache\Adapter\ArrayAdapter(),
            'api-key',
            'unused-connection-id',
            'unused-credential-id',
        );

        $controller = new BrowserSoftphoneController(
            $provisioningService,
            $tokenService,
            '+15551231234',
            '+15557654321',
        );

        $request = Request::create(
            '/poc/browser-softphone/token',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['destinationNumber' => '+15559876543'], JSON_THROW_ON_ERROR),
        );

        $response = $controller->token($request);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertSame('+15559876543', $payload['destinationNumber']);
    }
}
