<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallSummary;
use App\Entity\CallTranscript;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OllamaSummaryService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $model,
    ) {
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /** @return array<string, mixed> */
    public function summarize(CallTranscript $transcript): array
    {
        $payload = [
            'model' => $this->model,
            'stream' => false,
            'messages' => [[
                'role' => 'user',
                'content' => $this->buildPrompt($transcript),
            ]],
            'format' => [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'customer_intent' => ['type' => 'string'],
                    'participants' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'equipment_mentions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'appointment_mentions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'quote_or_price_mentions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'action_items' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'urgency' => ['type' => 'string'],
                    'sentiment' => ['type' => 'string'],
                ],
                'required' => ['summary', 'customer_intent', 'participants', 'equipment_mentions', 'appointment_mentions', 'quote_or_price_mentions', 'action_items', 'urgency', 'sentiment'],
            ],
        ];

        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/api/chat', [
            'json' => $payload,
            'timeout' => 120,
        ]);

        $data = $response->toArray(false);
        $content = $data['message']['content'] ?? null;
        if (!is_string($content) || '' === trim($content)) {
            throw new \RuntimeException('Ollama summary response did not contain message content.');
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Ollama summary response was not valid JSON.');
        }

        return $this->normalizeSummary($decoded);
    }

    public function applySummary(CallSummary $summary, array $result): void
    {
        $summary
            ->setProvider('ollama')
            ->setModel($this->model)
            ->setStatus('available')
            ->setSummaryJson($result)
            ->setSummaryText(is_string($result['summary'] ?? null) ? $result['summary'] : null)
            ->setErrorMessage(null)
            ->touch();
    }

    private function buildPrompt(CallTranscript $transcript): string
    {
        $channelMapping = $transcript->getChannelMapping();

        return <<<PROMPT
Summarize this phone call transcript and return strict JSON only.

Required JSON shape:
{
  "summary": "...",
  "customer_intent": "...",
  "participants": ["..."],
  "equipment_mentions": ["..."],
  "appointment_mentions": ["..."],
  "quote_or_price_mentions": ["..."],
  "action_items": ["..."],
  "urgency": "low|medium|high|unknown",
  "sentiment": "positive|neutral|negative|mixed|unknown"
}

Channel mapping:
{$this->jsonEncode($channelMapping)}

Transcript:
{$transcript->getTranscriptText()}
PROMPT;
    }

    /** @param array<string, mixed> $decoded
     *  @return array<string, mixed>
     */
    private function normalizeSummary(array $decoded): array
    {
        $arrayFields = [
            'participants',
            'equipment_mentions',
            'appointment_mentions',
            'quote_or_price_mentions',
            'action_items',
        ];

        foreach ($arrayFields as $field) {
            $value = $decoded[$field] ?? [];
            $decoded[$field] = is_array($value) ? array_values(array_map('strval', $value)) : [];
        }

        foreach (['summary', 'customer_intent', 'urgency', 'sentiment'] as $field) {
            $value = $decoded[$field] ?? null;
            $decoded[$field] = is_string($value) ? $value : '';
        }

        return $decoded;
    }

    /** @param array<string, mixed>|null $value */
    private function jsonEncode(?array $value): string
    {
        return null === $value ? 'null' : (json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'null');
    }
}
