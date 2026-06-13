<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiTranscriptionService
{
    private const string ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $model,
    ) {
    }

    /**
     * @return array{text: string, raw: array<string, mixed>, language: ?string, durationSeconds: ?int}
     */
    public function transcribeAudioFile(string $localFilePath, string $filename): array
    {
        if ('' === trim($this->apiKey)) {
            throw new \RuntimeException('OPENAI_API_KEY is missing.');
        }
        if ('' === trim($this->model)) {
            throw new \RuntimeException('OPENAI_TRANSCRIPTION_MODEL is missing.');
        }
        if (!is_file($localFilePath)) {
            throw new \RuntimeException(sprintf('Audio file "%s" does not exist.', $localFilePath));
        }

        $handle = fopen($localFilePath, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Audio file "%s" could not be opened.', $localFilePath));
        }

        $formData = new FormDataPart([
            'model' => $this->model,
            'response_format' => $this->supportsVerboseJson($this->model) ? 'verbose_json' : 'json',
            'file' => new DataPart($handle, $filename),
        ]);

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Accept' => 'application/json',
                ] + $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
                'timeout' => 300,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new \RuntimeException(sprintf(
                    'OpenAI transcription failed with HTTP %d.',
                    $statusCode,
                ));
            }

            $raw = $response->toArray(false);
            if (!is_array($raw) || !is_string($raw['text'] ?? null) || '' === trim($raw['text'])) {
                throw new \RuntimeException('OpenAI transcription response was malformed.');
            }

            return [
                'text' => $raw['text'],
                'raw' => $raw,
                'language' => is_string($raw['language'] ?? null) ? $raw['language'] : null,
                'durationSeconds' => $this->duration($raw['duration'] ?? null),
            ];
        } catch (TransportExceptionInterface $exception) {
            throw new \RuntimeException('OpenAI transcription request failed to send.', previous: $exception);
        } finally {
            fclose($handle);
        }
    }

    private function supportsVerboseJson(string $model): bool
    {
        return !str_starts_with($model, 'gpt-4o-transcribe')
            && !str_starts_with($model, 'gpt-4o-mini-transcribe');
    }

    private function duration(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_float($value) && $value >= 0) {
            return (int) round($value);
        }

        return null;
    }
}
