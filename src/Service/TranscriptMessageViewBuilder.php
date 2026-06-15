<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallTranscript;
use App\Repository\CallTranscriptSegmentRepository;

final class TranscriptMessageViewBuilder
{
    public function __construct(
        private readonly CallTranscriptSegmentRepository $segments,
    ) {
    }

    /**
     * @return list<array{
     *   side:string,
     *   label:string,
     *   text:string,
     *   occurredAt:string,
     *   endOccurredAt:string,
     *   segmentCount:int
     * }>
     */
    public function build(CallTranscript $transcript): array
    {
        $messages = [];

        foreach ($this->segments->findByTranscript($transcript) as $segment) {
            $text = trim($segment->getText());
            if ('' === $text) {
                continue;
            }

            $track = $this->segmentTrack($segment->getRawPayload());
            $side = $this->segmentSide($track, $segment->getSpeakerRole());
            $label = $this->segmentLabel($side, $track, $segment->getSpeakerRole());

            $lastIndex = array_key_last($messages);
            if (
                null !== $lastIndex
                && $messages[$lastIndex]['side'] === $side
                && $messages[$lastIndex]['label'] === $label
            ) {
                $messages[$lastIndex]['text'] .= "\n".$text;
                $messages[$lastIndex]['endOccurredAt'] = $segment->getOccurredAt()->format(DATE_ATOM);
                $messages[$lastIndex]['segmentCount']++;
                continue;
            }

            $messages[] = [
                'side' => $side,
                'label' => $label,
                'text' => $text,
                'occurredAt' => $segment->getOccurredAt()->format(DATE_ATOM),
                'endOccurredAt' => $segment->getOccurredAt()->format(DATE_ATOM),
                'segmentCount' => 1,
            ];
        }

        return $messages;
    }

    /** @param array<string, mixed>|null $rawPayload */
    private function segmentTrack(?array $rawPayload): ?string
    {
        $payload = $rawPayload['payload'] ?? null;
        $transcriptionData = is_array($payload) ? ($payload['transcription_data'] ?? null) : null;
        $track = is_array($transcriptionData) ? ($transcriptionData['transcription_track'] ?? null) : null;

        return is_string($track) && '' !== trim($track) ? strtolower(trim($track)) : null;
    }

    private function segmentSide(?string $track, ?string $speakerRole): string
    {
        return match (true) {
            'outbound' === $track => 'left',
            'inbound' === $track => 'right',
            in_array($speakerRole, ['caller', 'customer', 'target'], true) => 'right',
            in_array($speakerRole, ['vendor', 'agent', 'system'], true) => 'left',
            default => 'left',
        };
    }

    private function segmentLabel(string $side, ?string $track, ?string $speakerRole): string
    {
        if ('inbound' === $track) {
            return 'Inbound';
        }

        if ('outbound' === $track) {
            return 'Outbound';
        }

        if (null !== $speakerRole && '' !== trim($speakerRole)) {
            return ucfirst(str_replace('_', ' ', $speakerRole));
        }

        return 'right' === $side ? 'Inbound' : 'Outbound';
    }
}
