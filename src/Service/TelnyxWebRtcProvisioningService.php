<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TelnyxTelephonyCredentialDto;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TelnyxWebRtcProvisioningService
{
    private const string BASE_URL = 'https://api.telnyx.com/v2';
    private const string DEFAULT_CREDENTIAL_NAME = 'csr-browser-poc';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $credentialConnectionId,
        private readonly string $telephonyCredentialId,
    ) {
    }

    public function getOrCreateTelephonyCredential(string $name): TelnyxTelephonyCredentialDto
    {
        $this->assertConfigured();

        $expectedName = '' !== trim($name) ? trim($name) : self::DEFAULT_CREDENTIAL_NAME;
        $expectedResourceId = $this->expectedResourceId();
        $configuredCredentialId = trim($this->telephonyCredentialId);

        if ('' !== $configuredCredentialId) {
            $credential = $this->fetchTelephonyCredential($configuredCredentialId);
            $this->assertCredentialMatches($credential, $expectedName, $expectedResourceId, $configuredCredentialId);

            $this->logger->info('Using configured Telnyx WebRTC telephony credential.', [
                'telephony_credential_id' => $credential->id,
                'resource_id' => $credential->resourceId,
            ]);

            return $credential;
        }

        $credential = $this->findExistingTelephonyCredential($expectedName, $expectedResourceId);
        if (null !== $credential) {
            $this->logger->info('Found existing Telnyx WebRTC telephony credential.', [
                'telephony_credential_id' => $credential->id,
                'resource_id' => $credential->resourceId,
            ]);

            $this->logger->warning(sprintf(
                'TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID is not set. Set TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID=%s to pin this browser softphone credential.',
                $credential->id,
            ));

            return $credential;
        }

        $credential = $this->createTelephonyCredential($expectedName);
        $this->logger->info('Created Telnyx WebRTC telephony credential.', [
            'telephony_credential_id' => $credential->id,
            'resource_id' => $credential->resourceId,
        ]);
        $this->logger->warning(sprintf(
            'TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID is not set. Set TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID=%s to pin this browser softphone credential.',
            $credential->id,
        ));

        return $credential;
    }

    /**
     * @return list<string>
     */
    public function listCredentialConnectionIds(): array
    {
        $this->assertConfigured();

        $response = $this->request('GET', self::BASE_URL.'/credential_connections', [
            'query' => [
                'page' => [
                    'size' => 250,
                ],
            ],
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        $decoded = $this->decodeJsonResponse($response, 'credential connections list');
        $ids = [];

        foreach ((array) ($decoded['data'] ?? []) as $item) {
            $id = $this->extractStringValue($item, ['id', 'attributes.id']);
            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function fetchTelephonyCredential(string $telephonyCredentialId): TelnyxTelephonyCredentialDto
    {
        $response = $this->request('GET', sprintf('%s/telephony_credentials/%s', self::BASE_URL, rawurlencode($telephonyCredentialId)), [
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        return $this->parseTelephonyCredential(
            $this->decodeJsonResponse($response, 'telephony credential'),
        );
    }

    private function findExistingTelephonyCredential(string $name, string $expectedResourceId): ?TelnyxTelephonyCredentialDto
    {
        $response = $this->request('GET', self::BASE_URL.'/telephony_credentials', [
            'headers' => [
                'Accept' => 'application/json',
            ],
            'query' => [
                'filter' => [
                    'name' => $name,
                    'resourceID' => $expectedResourceId,
                    'resource_id' => $expectedResourceId,
                ],
                'page' => [
                    'size' => 250,
                ],
            ],
        ]);

        $decoded = $this->decodeJsonResponse($response, 'telephony credentials list');
        foreach ((array) ($decoded['data'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $credentialId = $this->extractStringValue($item, ['id', 'attributes.id']);
            $credentialName = $this->extractStringValue($item, ['name', 'attributes.name']);
            $resourceId = $this->extractStringValue($item, ['resource_id', 'resourceID', 'attributes.resource_id', 'attributes.resourceID']);
            $sipUsername = $this->extractStringValue($item, ['sip_username', 'attributes.sip_username']);

            if (null === $credentialId || null === $credentialName || null === $resourceId) {
                continue;
            }

            if ($credentialName === $name && $resourceId === $expectedResourceId) {
                return new TelnyxTelephonyCredentialDto($credentialId, $credentialName, $resourceId, $sipUsername);
            }
        }

        return null;
    }

    private function createTelephonyCredential(string $name): TelnyxTelephonyCredentialDto
    {
        $response = $this->request('POST', self::BASE_URL.'/telephony_credentials', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'name' => $name,
                'connection_id' => $this->credentialConnectionId,
            ],
        ]);

        return $this->parseTelephonyCredential(
            $this->decodeJsonResponse($response, 'telephony credential create'),
        );
    }

    private function assertCredentialMatches(TelnyxTelephonyCredentialDto $credential, string $expectedName, string $expectedResourceId, string $configuredCredentialId): void
    {
        if ($credential->resourceId !== $expectedResourceId) {
            throw new \RuntimeException(sprintf(
                'Configured TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID "%s" belongs to resource "%s", expected "%s".',
                $configuredCredentialId,
                $credential->resourceId,
                $expectedResourceId,
            ));
        }

        if ($credential->name !== $expectedName) {
            throw new \RuntimeException(sprintf(
                'Configured TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID "%s" has name "%s", expected "%s".',
                $configuredCredentialId,
                $credential->name,
                $expectedName,
            ));
        }
    }

    private function expectedResourceId(): string
    {
        $connectionId = trim($this->credentialConnectionId);
        if ('' === $connectionId) {
            throw new \RuntimeException('TELNYX_WEBRTC_CREDENTIAL_CONNECTION_ID is missing.');
        }

        return 'connection:'.$connectionId;
    }

    private function assertConfigured(): void
    {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('TELNYX_API_KEY is missing.');
        }
    }

    private function parseTelephonyCredential(array $payload): TelnyxTelephonyCredentialDto
    {
        $credential = $payload['data'] ?? $payload;
        if (!is_array($credential)) {
            throw new \RuntimeException('Telnyx telephony credential response was not an object.');
        }

        $id = $this->extractStringValue($credential, ['id', 'attributes.id']);
        $name = $this->extractStringValue($credential, ['name', 'attributes.name']);
        $resourceId = $this->extractStringValue($credential, ['resource_id', 'resourceID', 'attributes.resource_id', 'attributes.resourceID']);
        $sipUsername = $this->extractStringValue($credential, ['sip_username', 'attributes.sip_username']);

        if (null === $id || '' === $id) {
            throw new \RuntimeException('Telnyx telephony credential response did not contain an id.');
        }

        if (null === $name || '' === $name) {
            throw new \RuntimeException(sprintf('Telnyx telephony credential "%s" did not contain a name.', $id));
        }

        if (null === $resourceId || '' === $resourceId) {
            throw new \RuntimeException(sprintf('Telnyx telephony credential "%s" did not contain a resource id.', $id));
        }

        return new TelnyxTelephonyCredentialDto($id, $name, $resourceId, $sipUsername);
    }

    private function request(string $method, string $url, array $options = []): ResponseInterface
    {
        try {
            $headers = $options['headers'] ?? [];
            unset($options['headers']);
            $options['headers'] = ['Authorization' => 'Bearer '.$this->apiKey] + $headers;

            return $this->httpClient->request($method, $url, $options);
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException(sprintf('Telnyx request to "%s" failed: %s', $url, $exception->getMessage()), 0, $exception);
        }
    }

    private function decodeJsonResponse(ResponseInterface $response, string $operation): array
    {
        $statusCode = $response->getStatusCode();
        $content = trim($response->getContent(false));

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf(
                'Telnyx %s request failed with HTTP %d%s',
                $operation,
                $statusCode,
                '' !== $content ? ': '.$content : '',
            ));
        }

        if ('' === $content) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf('Telnyx %s response was not valid JSON.', $operation), 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Telnyx %s response was not an object.', $operation));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
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
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
