<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallSession;
use App\Entity\Tenant;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelnyxWebrtcTokenService
{
    private const string BASE_URL = 'https://api.telnyx.com/v2';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $telephonyCredentialId,
    ) {
    }

    /**
     * @return array{token:string,expiresAt:\DateTimeImmutable,rawResponse:string}
     */
    public function issue(Tenant $tenant, User $user, CallSession $callSession): array
    {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('TELNYX_API_KEY is missing.');
        }

        if ('' === trim($this->telephonyCredentialId)) {
            throw new \RuntimeException('TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID is missing.');
        }

        $this->logger->info('Issuing Telnyx WebRTC token for browser call.', [
            'tenant_id' => $tenant->getId(),
            'user_id' => $user->getId(),
            'call_session_id' => $callSession->getId(),
            'provider_session_id' => $callSession->getProviderSessionId(),
        ]);

        $response = $this->httpClient->request('POST', sprintf('%s/telephony_credentials/%s/token', self::BASE_URL, rawurlencode($this->telephonyCredentialId)), [
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Accept' => 'application/json, text/plain;q=0.9',
            ],
        ]);

        $content = trim($response->getContent(false));
        if ('' === $content) {
            throw new \RuntimeException('Telnyx WebRTC token response was empty.');
        }

        $token = $this->extractToken($content);
        $expiresAt = $this->extractExpiry($token);

        return [
            'token' => $token,
            'expiresAt' => $expiresAt,
            'rawResponse' => $content,
        ];
    }

    private function extractToken(string $content): string
    {
        $decoded = json_decode($content, true);
        if (is_string($decoded) && '' !== trim($decoded)) {
            return trim($decoded);
        }

        if (is_array($decoded)) {
            foreach (['token', 'access_token', 'jwt'] as $key) {
                if (is_string($decoded[$key] ?? null) && '' !== trim($decoded[$key])) {
                    return trim((string) $decoded[$key]);
                }
            }
        }

        $token = trim($content, " \t\n\r\0\x0B\"");
        if ('' === $token) {
            throw new \RuntimeException('Telnyx WebRTC token response did not contain a token.');
        }

        return $token;
    }

    private function extractExpiry(string $token): \DateTimeImmutable
    {
        $parts = explode('.', $token);
        if (3 !== count($parts)) {
            throw new \RuntimeException('Telnyx WebRTC token was not a JWT.');
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        if (!is_array($payload) || !is_int($payload['exp'] ?? null)) {
            throw new \RuntimeException('Telnyx WebRTC token did not contain an expiry claim.');
        }

        return (new \DateTimeImmutable())->setTimestamp($payload['exp']);
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (false === $decoded) {
            throw new \RuntimeException('Telnyx WebRTC token payload was not valid base64url.');
        }

        return $decoded;
    }
}
