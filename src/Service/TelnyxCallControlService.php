<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TelnyxCallControlService
{
    private const string BASE_URL = 'https://api.telnyx.com/v2';
    private const string RECORDING_START_ACTION = 'record_start';
    private const string TRANSCRIPTION_START_ACTION = 'transcription_start';

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
    /** @param array<string, mixed> $options */
    public function startRecording(string $callControlId, array $options = []): ?array
    {
        $commandId = $this->stringOption($options, 'command_id');
        unset($options['command_id']);

        $format = $this->stringOption($options, 'format') ?? 'wav';
        if (!in_array($format, ['wav', 'mp3'], true)) {
            throw new \InvalidArgumentException('Recording format must be "wav" or "mp3".');
        }

        $body = [
            'format' => $format,
            'channels' => $this->stringOption($options, 'channels') ?? 'dual',
        ];

        foreach ($options as $key => $value) {
            $body[$key] = $value;
        }

        return $this->postAction($callControlId, self::RECORDING_START_ACTION, $body, [], $commandId);
    }

    /** @param array<string, mixed> $options */
    public function startTranscription(string $callControlId, array $options = []): ?array
    {
        $commandId = $this->stringOption($options, 'command_id');
        unset($options['command_id']);

        return $this->postAction(
            $callControlId,
            self::TRANSCRIPTION_START_ACTION,
            $options,
            [],
            $commandId,
        );
    }

    /** @return array<string, mixed>|null */
    public function stopRecording(string $callControlId, ?string $commandId = null): ?array
    {
        return $this->postAction($callControlId, 'record_stop', [], [], $commandId);
    }

    /** @return array<string, mixed>|null */
    public function stopTranscription(string $callControlId, ?string $commandId = null): ?array
    {
        return $this->postAction($callControlId, 'transcription_stop', [], [], $commandId);
    }

    /** @return array<string, mixed>|null */
    public function playDtmf(string $callControlId, string $dtmfTones, ?string $commandId = null): ?array
    {
        $body = [
            'dtmf_tones' => trim($dtmfTones),
            'duration_ms' => 200,
            'volume_db' => 0,
            'inter_tone_gap_ms' => 50,
        ];

        if ('' === $body['dtmf_tones']) {
            return null;
        }

        return $this->post(
            sprintf('/calls/%s/actions/play_dtmf', rawurlencode($callControlId)),
            'play_dtmf',
            $body,
            ['call_control_id' => $callControlId],
            $commandId,
        );
    }

    /** @return array<string, mixed>|null */
    public function mute(string $callControlId, bool $mute = true, ?string $commandId = null): ?array
    {
        // Telnyx WebRTC supports muting via the play_audio action with empty text.
        // For platform control: we use 'pause' on active audio or send a specific mute flag.
        // Since Telnyx Call Control doesn't have a dedicated mute action, we use
        // the call-control pause endpoint which stops media relay for this leg.
        $body = [
            'action' => $mute ? 'pause' : 'resume',
        ];

        $action = $mute ? 'pause' : 'resume';
        return $this->post(
            sprintf('/calls/%s/actions/%s', rawurlencode($callControlId), $action),
            $action,
            $body,
            ['call_control_id' => $callControlId],
            $commandId,
        );
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

        $logContext = [
            'action' => $action,
            'request_body' => $this->sanitizePayload($body),
        ] + $context;
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
                    'response' => $this->sanitizeResponse($responseBody),
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

    /** @param array<string, mixed> $payload */
    private function sanitizePayload(array $payload): array
    {
        return $payload;
    }

    private function sanitizeResponse(string $responseBody): string|array
    {
        try {
            $decoded = json_decode($responseBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return substr($responseBody, 0, 1000);
        }

        return is_array($decoded) ? $decoded : substr($responseBody, 0, 1000);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : null;
    }
}
