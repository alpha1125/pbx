<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallSession;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\TelnyxWebrtcTokenService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
            'api-key',
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
}
