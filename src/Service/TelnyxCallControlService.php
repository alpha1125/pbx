<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TelnyxCallControlService
{
    private const string BASE_URL = 'https://api.telnyx.com/v2';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function answer(string $callControlId, ?string $commandId = null): ?array
    {
        return $this->postAction($callControlId, 'answer', [], [], $commandId);
    }

    /** @return array<string, mixed>|null */
    public function speak(
        string $callControlId,
        string $text,
        ?string $clientState = null,
        ?string $commandId = null,
    ): ?array {
        $body = [
            'payload' => $text,
            'payload_type' => 'text',
            'voice' => 'female',
            'language' => 'en-US',
        ];

        if (null !== $clientState) {
            $body['client_state'] = $clientState;
        }

        return $this->postAction($callControlId, 'speak', $body, [], $commandId);
    }

    /** @return array<string, mixed>|null */
    public function hangup(string $callControlId, ?string $commandId = null): ?array
    {
        return $this->postAction($callControlId, 'hangup', [], [], $commandId);
    }

    /** @return array<string, mixed>|null */
    public function startRecording(string $callControlId, string $format = 'wav', ?string $commandId = null): ?array
    {
        if (!in_array($format, ['wav', 'mp3'], true)) {
            throw new \InvalidArgumentException('Recording format must be "wav" or "mp3".');
        }

        return $this->postAction($callControlId, 'record_start', [
            'format' => $format,
            'channels' => 'dual',
            'recording_track' => 'both',
            'transcription' => false,
        ], [], $commandId);
    }

    /** @return array<string, mixed>|null */
    public function stopRecording(string $callControlId, ?string $commandId = null): ?array
    {
        return $this->postAction($callControlId, 'record_stop', [], [], $commandId);
    }

    /** @return array<string, mixed>|null */
    public function dial(
        string $connectionId,
        string $from,
        string $to,
        ?string $clientState = null,
        ?int $timeoutSecs = null,
        ?string $commandId = null,
    ): ?array {
        $body = [
            'connection_id' => $connectionId,
            'from' => $from,
            'to' => $to,
        ];

        if (null !== $clientState) {
            $body['client_state'] = $clientState;
        }

        if (null !== $timeoutSecs) {
            $body['timeout_secs'] = $timeoutSecs;
        }

        return $this->post('/calls', 'dial', $body, [
            'connection_id' => $connectionId,
            'from' => $from,
            'to' => $to,
        ], $commandId);
    }

    /** @return array<string, mixed>|null */
    public function bridge(
        string $callControlId,
        string $targetCallControlId,
        ?string $commandId = null,
        bool $preventDoubleBridge = true,
    ): ?array {
        return $this->postAction($callControlId, 'bridge', [
            'call_control_id' => $targetCallControlId,
            'prevent_multiple_bridges' => $preventDoubleBridge,
        ], [
            'target_call_control_id' => $targetCallControlId,
            'prevent_multiple_bridges' => $preventDoubleBridge,
        ], $commandId);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function postAction(
        string $callControlId,
        string $action,
        array $body = [],
        array $context = [],
        ?string $commandId = null,
    ): ?array {
        return $this->post(
            sprintf('/calls/%s/actions/%s', rawurlencode($callControlId), $action),
            $action,
            $body,
            ['call_control_id' => $callControlId] + $context,
            $commandId,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function post(
        string $path,
        string $action,
        array $body,
        array $context,
        ?string $commandId = null,
    ): ?array {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('TELNYX_API_KEY is missing.');
        }

        if (null !== $commandId) {
            $body['command_id'] = $commandId;
        }

        $logContext = ['action' => $action] + $context;
        $this->logger->info('Sending Telnyx Call Control action.', $logContext);

        try {
            $response = $this->httpClient->request(
                'POST',
                self::BASE_URL.$path,
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->apiKey,
                        'Accept' => 'application/json',
                    ],
                    'json' => [] === $body ? new \stdClass() : $body,
                ],
            );

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('Telnyx Call Control action failed.', $logContext + [
                    'status_code' => $statusCode,
                    'response' => substr($responseBody, 0, 1000),
                ]);

                throw new \RuntimeException(sprintf(
                    'Telnyx action "%s" failed with HTTP %d.',
                    $action,
                    $statusCode,
                ));
            }

            $this->logger->info('Telnyx Call Control action succeeded.', $logContext + [
                'status_code' => $statusCode,
            ]);

            try {
                $decoded = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }

            return is_array($decoded) ? $decoded : null;
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Telnyx Call Control transport failure.', $logContext + [
                'exception' => $exception,
            ]);

            throw new \RuntimeException(
                sprintf('Telnyx action "%s" could not be sent.', $action),
                previous: $exception,
            );
        }
    }
}
