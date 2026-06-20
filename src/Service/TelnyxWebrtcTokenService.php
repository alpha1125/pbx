<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallSession;
use App\Entity\Tenant;
use App\Entity\User;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelnyxWebrtcTokenService
{
    private const string BASE_URL = 'https://api.telnyx.com/v2';
    private const string CACHE_KEY_PREFIX = 'telnyx_webrtc_telephony_credential_id.';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $apiKey,
        private readonly string $connectionId,
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

        $telephonyCredentialId = $this->resolveTelephonyCredentialId($tenant);

        $this->logger->info('Issuing Telnyx WebRTC token for browser call.', [
            'tenant_id' => $tenant->getId(),
            'user_id' => $user->getId(),
            'call_session_id' => $callSession->getId(),
            'provider_session_id' => $callSession->getProviderSessionId(),
            'telephony_credential_id' => $telephonyCredentialId,
        ]);

        $token = $this->generateToken($telephonyCredentialId);
        $expiresAt = $this->extractExpiry($token);

        return [
            'token' => $token,
            'expiresAt' => $expiresAt,
            'rawResponse' => $token,
        ];
    }

    public function generateToken(string $telephonyCredentialId): string
    {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('TELNYX_API_KEY is missing.');
        }

        $response = $this->httpClient->request('POST', sprintf('%s/telephony_credentials/%s/token', self::BASE_URL, rawurlencode(trim($telephonyCredentialId))), [
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Accept' => 'application/json, text/plain;q=0.9',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $content = trim($response->getContent(false));
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Telnyx WebRTC token request failed with HTTP %d%s',
                $statusCode,
                '' !== $content ? ': '.$content : '',
            ));
        }

        if ('' === $content) {
            throw new \RuntimeException('Telnyx WebRTC token response was empty.');
        }

        return $this->extractToken($content);
    }

    private function resolveTelephonyCredentialId(Tenant $tenant): string
    {
        $configuredCredentialId = trim($this->telephonyCredentialId);
        if ('' !== $configuredCredentialId) {
            return $configuredCredentialId;
        }

        $cacheKey = self::CACHE_KEY_PREFIX.sha1($this->connectionId);
        $cached = $this->cache->getItem($cacheKey);
        if ($cached->isHit()) {
            $credentialId = trim((string) $cached->get());
            if ('' !== $credentialId) {
                return $credentialId;
            }
        }

        if ('' === trim($this->connectionId)) {
            throw new \RuntimeException('TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID is missing and TELNYX_CONNECTION_ID is missing, so a credential cannot be resolved.');
        }

        $resourceId = 'connection:'.trim($this->connectionId);
        $existingCredentialId = $this->findExistingCredentialId($resourceId);
        if (null !== $existingCredentialId) {
            $this->storeCredentialId($cacheKey, $existingCredentialId);

            return $existingCredentialId;
        }

        throw new \RuntimeException(sprintf(
            'TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID is missing and Telnyx did not return an existing telephony credential for connection "%s". Provision a WebRTC telephony credential in Telnyx and set TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID.',
            trim($this->connectionId),
        ));
    }

    private function findExistingCredentialId(string $resourceId): ?string
    {
        $response = $this->httpClient->request('GET', self::BASE_URL.'/telephony_credentials', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Accept' => 'application/json',
            ],
            'query' => [
                'filter' => [
                    'resource_id' => $resourceId,
                    'resourceID' => $resourceId,
                ],
                'page' => [
                    'size' => 250,
                ],
            ],
        ]);

        $content = trim($response->getContent(false));
        if ('' === $content) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        foreach ((array) ($decoded['data'] ?? []) as $credential) {
            $credentialResourceId = $this->extractStringValue($credential, ['resource_id', 'resourceID', 'attributes.resource_id', 'attributes.resourceID']);
            $credentialId = $this->extractStringValue($credential, ['id', 'attributes.id']);

            if (null !== $credentialResourceId && $resourceId === $credentialResourceId && null !== $credentialId) {
                return $credentialId;
            }
        }

        return null;
    }

    private function storeCredentialId(string $cacheKey, string $credentialId): void
    {
        $item = $this->cache->getItem($cacheKey);
        $item
            ->set($credentialId)
            ->expiresAfter(86400);
        $this->cache->save($item);
    }

    /**
     * @param list<string> $paths
     */
    private function extractStringValue(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $this->readPath($payload, $path);
            if (is_string($value)) {
                $value = trim($value);
                if ('' !== $value) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     */
    private function readPath(array $payload, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $payload;

        foreach ($segments as $segment) {
            if (!is_array($current)) {
                return null;
            }

            if (!array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
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
