<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallSession;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\TelnyxWebrtcTokenService;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TelnyxWebrtcTokenServiceTest extends TestCase
{
    public function testIssuePostsToTelnyxAndExtractsExpiryFromJwt(): void
    {
        $payload = ['exp' => 1893456000, 'sub' => 'credential-1'];
        $token = 'header.'.rtrim(strtr(base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=').'.signature';

        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $token): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse($token, ['http_code' => 201]);
        });

        $service = new TelnyxWebrtcTokenService(
            $httpClient,
            new NullLogger(),
            new ArrayAdapter(),
            'api-key',
            'connection-1',
            'credential-1',
        );

        $result = $service->issue(
            (new Tenant('Tenant One'))->setEmail('tenant@example.com'),
            (new User())->setEmail('csr@example.com')->setPassword('unused'),
            new CallSession('call-session-1'),
        );

        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://api.telnyx.com/v2/telephony_credentials/credential-1/token', $requests[0]['url']);
        self::assertSame($token, $result['token']);
        self::assertSame(1893456000, $result['expiresAt']->getTimestamp());
        self::assertSame($token, $result['rawResponse']);
    }

    public function testIssueResolvesExistingCredentialWhenConfiguredIdIsMissing(): void
    {
        $payload = ['exp' => 1893456000, 'sub' => 'credential-2'];
        $token = 'header.'.rtrim(strtr(base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=').'.signature';

        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $token): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            if ('GET' === $method) {
                return new MockResponse(json_encode([
                    'data' => [
                        [
                            'id' => 'credential-2',
                            'resource_id' => 'connection:connection-2',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            return new MockResponse($token, ['http_code' => 201]);
        });

        $service = new TelnyxWebrtcTokenService(
            $httpClient,
            new NullLogger(),
            new ArrayAdapter(),
            'api-key',
            'connection-2',
            '',
        );

        $result = $service->issue(
            (new Tenant('Tenant One'))->setEmail('tenant@example.com'),
            (new User())->setEmail('csr@example.com')->setPassword('unused'),
            new CallSession('call-session-2'),
        );

        self::assertCount(2, $requests);
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame('https://api.telnyx.com/v2/telephony_credentials?filter[resource_id]=connection:connection-2&filter[resourceID]=connection:connection-2&page[size]=250', $requests[0]['url']);
        self::assertSame('https://api.telnyx.com/v2/telephony_credentials/credential-2/token', $requests[1]['url']);
        self::assertSame($token, $result['token']);
    }

    public function testIssueUsesExistingCredentialWhenResponseWrapsCredentialInDataArray(): void
    {
        $payload = ['exp' => 1893456000, 'sub' => 'credential-4'];
        $token = 'header.'.rtrim(strtr(base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=').'.signature';

        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $token): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            if ('GET' === $method) {
                return new MockResponse(json_encode([
                    'data' => [
                        [
                            'id' => 'credential-4',
                            'resource_id' => 'connection:connection-4',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            return new MockResponse($token, ['http_code' => 201]);
        });

        $service = new TelnyxWebrtcTokenService(
            $httpClient,
            new NullLogger(),
            new ArrayAdapter(),
            'api-key',
            'connection-4',
            '',
        );

        $result = $service->issue(
            (new Tenant('Tenant One'))->setEmail('tenant@example.com'),
            (new User())->setEmail('csr@example.com')->setPassword('unused'),
            new CallSession('call-session-4'),
        );

        self::assertCount(2, $requests);
        self::assertSame('https://api.telnyx.com/v2/telephony_credentials/credential-4/token', $requests[1]['url']);
        self::assertSame($token, $result['token']);
    }

    public function testIssueRejectsMissingCredentialWhenNoneExist(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse(json_encode(['data' => []], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $service = new TelnyxWebrtcTokenService(
            $httpClient,
            new NullLogger(),
            new ArrayAdapter(),
            'api-key',
            'connection-5',
            '',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID is missing and Telnyx did not return an existing telephony credential for connection "connection-5"');

        $service->issue(
            (new Tenant('Tenant One'))->setEmail('tenant@example.com'),
            (new User())->setEmail('csr@example.com')->setPassword('unused'),
            new CallSession('call-session-5'),
        );

        self::assertCount(1, $requests);
    }

    public function testGenerateTokenAcceptsJsonTokenResponse(): void
    {
        $token = 'header.payload.signature';
        $httpClient = new MockHttpClient(static fn (string $method, string $url, array $options): MockResponse => new MockResponse(json_encode(['token' => $token], JSON_THROW_ON_ERROR), ['http_code' => 201]));

        $service = new TelnyxWebrtcTokenService(
            $httpClient,
            new NullLogger(),
            new ArrayAdapter(),
            'api-key',
            'connection-1',
            'credential-1',
        );

        self::assertSame($token, $service->generateToken('credential-1'));
    }
}
