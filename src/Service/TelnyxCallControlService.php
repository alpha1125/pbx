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

    public function answer(string $callControlId): void
    {
        $this->postAction($callControlId, 'answer');
    }

    public function speak(string $callControlId, string $text): void
    {
        $this->postAction($callControlId, 'speak', [
            'payload' => $text,
            'payload_type' => 'text',
            'voice' => 'female',
            'language' => 'en-US',
        ]);
    }

    public function hangup(string $callControlId): void
    {
        $this->postAction($callControlId, 'hangup');
    }

    public function startRecording(string $callControlId, string $format = 'wav'): void
    {
        if (!in_array($format, ['wav', 'mp3'], true)) {
            throw new \InvalidArgumentException('Recording format must be "wav" or "mp3".');
        }

        $this->postAction($callControlId, 'record_start', [
            'format' => $format,
            'channels' => 'dual',
            'recording_track' => 'both',
            'transcription' => false,
        ]);
    }

    public function stopRecording(string $callControlId): void
    {
        $this->postAction($callControlId, 'record_stop');
    }

    public function dial(
        string $connectionId,
        string $from,
        string $to,
        ?string $clientState = null,
    ): void {
        $body = [
            'connection_id' => $connectionId,
            'from' => $from,
            'to' => $to,
        ];

        if (null !== $clientState) {
            $body['client_state'] = $clientState;
        }

        $this->post('/calls', 'dial', $body, [
            'connection_id' => $connectionId,
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function bridge(string $callControlId, string $targetCallControlId): void
    {
        $this->postAction($callControlId, 'bridge', [
            'call_control_id' => $targetCallControlId,
        ], [
            'target_call_control_id' => $targetCallControlId,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     */
    private function postAction(
        string $callControlId,
        string $action,
        array $body = [],
        array $context = [],
    ): void {
        $this->post(
            sprintf('/calls/%s/actions/%s', rawurlencode($callControlId), $action),
            $action,
            $body,
            ['call_control_id' => $callControlId] + $context,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $context
     */
    private function post(string $path, string $action, array $body, array $context): void
    {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('TELNYX_API_KEY is missing.');
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
            if ($statusCode < 200 || $statusCode >= 300) {
                $responseBody = $response->getContent(false);

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
