<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallSession;
use App\Entity\CallSummary;
use App\Entity\CallTranscript;
use App\Entity\Contact;
use App\Entity\Property;
use App\Repository\CallSummaryLookupInterface;
use App\Repository\ContactRepository;
use App\Repository\PropertyContactRepository;
use App\Repository\PropertyRepository;

final class CallInsightService
{
    public function __construct(
        private readonly CallSummaryLookupInterface $summaries,
        private readonly ContactRepository $contacts,
        private readonly PropertyRepository $properties,
        private readonly PropertyContactRepository $propertyContacts,
        private readonly CrmInputNormalizer $normalizer,
    ) {
    }

    /**
     * @return array{
     *   summary:string,
     *   customer_intent:string,
     *   urgency:string,
     *   sentiment:string,
     *   recommended_disposition:string,
     *   next_step:string,
     *   equipment_mentions:list<string>,
     *   appointment_mentions:list<string>,
     *   quote_or_price_mentions:list<string>,
     *   matched_contacts:list<array<string, mixed>>,
     *   matched_properties:list<array<string, mixed>>,
     *   action_items:list<string>,
     *   evidence:list<string>
     * }
     */
    public function buildForTranscript(CallTranscript $transcript): array
    {
        return $this->build($transcript, $this->summaries->findOneByTranscript($transcript));
    }

    /**
     * @return array{
     *   summary:string,
     *   customer_intent:string,
     *   urgency:string,
     *   sentiment:string,
     *   recommended_disposition:string,
     *   next_step:string,
     *   equipment_mentions:list<string>,
     *   appointment_mentions:list<string>,
     *   quote_or_price_mentions:list<string>,
     *   matched_contacts:list<array<string, mixed>>,
     *   matched_properties:list<array<string, mixed>>,
     *   action_items:list<string>,
     *   evidence:list<string>
     * }
     */
    public function buildForSummary(CallSummary $summary): array
    {
        return $this->build($summary->getCallTranscript(), $summary);
    }

    /**
     * @return array{
     *   summary:string,
     *   customer_intent:string,
     *   urgency:string,
     *   sentiment:string,
     *   recommended_disposition:string,
     *   next_step:string,
     *   equipment_mentions:list<string>,
     *   appointment_mentions:list<string>,
     *   quote_or_price_mentions:list<string>,
     *   matched_contacts:list<array<string, mixed>>,
     *   matched_properties:list<array<string, mixed>>,
     *   action_items:list<string>,
     *   evidence:list<string>
     * }
     */
    private function build(?CallTranscript $transcript, ?CallSummary $summary): array
    {
        $session = $transcript?->getCallSession();
        $tenant = $session?->getTenant();
        if (null === $session || null === $tenant) {
            return $this->emptyInsights();
        }

        $summaryJson = is_array($summary?->getSummaryJson()) ? $summary->getSummaryJson() : [];
        $summaryText = trim((string) ($summary?->getSummaryText() ?? ''));
        $transcriptText = trim((string) ($transcript?->getTranscriptText() ?? ''));
        $corpus = $this->buildCorpus($session, $summaryJson, $summaryText, $transcriptText);
        $properties = $this->properties->findByTenant($tenant, 1, 500);

        $matchedContacts = $this->findMatchedContacts($tenant, $session, $corpus);
        $matchedProperties = $this->findMatchedProperties($tenant, $session, $corpus, $matchedContacts, $properties);

        $recommendedDisposition = $this->determineDisposition($summaryJson, $corpus, $matchedContacts, $matchedProperties);
        $nextStep = $this->determineNextStep($summaryJson, $recommendedDisposition, $matchedContacts, $matchedProperties);

        return [
            'summary' => '' !== $summaryText ? $summaryText : $this->excerpt($transcriptText),
            'customer_intent' => $this->summaryString($summaryJson, 'customer_intent'),
            'urgency' => $this->summaryString($summaryJson, 'urgency'),
            'sentiment' => $this->summaryString($summaryJson, 'sentiment'),
            'recommended_disposition' => $recommendedDisposition,
            'next_step' => $nextStep,
            'equipment_mentions' => $this->summaryArray($summaryJson, 'equipment_mentions'),
            'appointment_mentions' => $this->summaryArray($summaryJson, 'appointment_mentions'),
            'quote_or_price_mentions' => $this->summaryArray($summaryJson, 'quote_or_price_mentions'),
            'matched_contacts' => $matchedContacts,
            'matched_properties' => $matchedProperties,
            'action_items' => $this->summaryArray($summaryJson, 'action_items'),
            'evidence' => array_values(array_filter([
                $session->getInboundFrom(),
                $session->getInboundTo(),
                $summaryText,
                $transcriptText,
            ])),
        ];
    }

    /**
     * @param list<array<string, mixed>> $matchedContacts
     *
     * @param list<Property> $properties
     *
     * @return list<array<string, mixed>>
     */
    private function findMatchedProperties(\App\Entity\Tenant $tenant, CallSession $session, string $corpus, array $matchedContacts, array $properties): array
    {
        $matches = [];
        $seen = [];

        if (null !== $session->getProperty() && null !== $session->getProperty()->getId()) {
            $property = $session->getProperty();
            $matches[] = $this->propertyMatch($property, 'Linked property from the call session.', 'high');
            $seen[$property->getId()] = true;
        }

        foreach ($matchedContacts as $contactMatch) {
            $contactId = (int) ($contactMatch['contactId'] ?? 0);
            if ($contactId <= 0) {
                continue;
            }

            $contact = $this->contacts->findOneByTenantAndId($tenant, $contactId);
            if (!$contact instanceof Contact) {
                continue;
            }

            foreach ($properties as $property) {
                if (null === $property->getId() || isset($seen[$property->getId()])) {
                    continue;
                }

                if (null === $this->propertyContacts->findOneByTenantPropertyAndContact($tenant, $property, $contact)) {
                    continue;
                }

                $matches[] = $this->propertyMatch($property, sprintf('Linked through matched contact %s.', $contact->getDisplayName()), 'medium');
                $seen[$property->getId()] = true;
            }
        }

        foreach ($properties as $property) {
            if (null === $property->getId() || isset($seen[$property->getId()])) {
                continue;
            }

            if ($this->propertyMatchesCorpus($property, $corpus)) {
                $matches[] = $this->propertyMatch($property, 'Matched property address or postal code in the transcript.', 'medium');
                $seen[$property->getId()] = true;
            }
        }

        return array_slice($matches, 0, 3);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findMatchedContacts(\App\Entity\Tenant $tenant, CallSession $session, string $corpus): array
    {
        $phones = array_values(array_filter([
            $this->normalizer->normalizePhoneOrNull($session->getInboundFrom()),
            $this->normalizer->normalizePhoneOrNull($session->getInboundTo()),
            ...$this->extractPhonesFromText($corpus),
        ]));

        $matches = [];
        foreach ($this->contacts->findByTenant($tenant) as $contact) {
            $reason = null;
            $confidence = 'low';
            $contactPhone = $this->normalizer->normalizePhoneOrNull($contact->getPrimaryPhone());

            if (null !== $contactPhone && in_array($contactPhone, $phones, true)) {
                $reason = sprintf('Matched phone number %s.', $contactPhone);
                $confidence = 'high';
            } elseif ('' !== $contact->getDisplayName() && $this->containsNormalized($corpus, $contact->getDisplayName())) {
                $reason = sprintf('Matched contact name %s in the transcript.', $contact->getDisplayName());
                $confidence = 'medium';
            }

            if (null === $reason) {
                continue;
            }

            $matches[] = [
                'contactId' => $contact->getId(),
                'displayName' => $contact->getDisplayName(),
                'primaryPhone' => $contact->getPrimaryPhone(),
                'reason' => $reason,
                'confidence' => $confidence,
            ];
        }

        return array_slice($matches, 0, 3);
    }

    /**
     * @param array<string, mixed> $summaryJson
     * @param list<array<string, mixed>> $matchedContacts
     * @param list<array<string, mixed>> $matchedProperties
     */
    private function determineDisposition(array $summaryJson, string $corpus, array $matchedContacts, array $matchedProperties): string
    {
        $quoted = $this->summaryArray($summaryJson, 'quote_or_price_mentions');
        $appointments = $this->summaryArray($summaryJson, 'appointment_mentions');
        $actionItems = $this->summaryArray($summaryJson, 'action_items');
        $urgency = $this->summaryString($summaryJson, 'urgency');

        if ($this->containsAny($corpus, ['spam', 'telemarketer', 'solicitation', 'robotext'])) {
            return 'spam';
        }

        if ([] !== $quoted || $this->containsAny($corpus, ['quote', 'estimate', 'pricing', 'price', 'cost', 'repair'])) {
            return 'quote_requested';
        }

        if ([] !== $appointments || $this->containsAny($corpus, ['book', 'schedule', 'appointment', 'visit'])) {
            return $this->containsAny($corpus, ['confirmed', 'scheduled', 'booked'])
                ? 'job_booked'
                : 'follow_up_required';
        }

        if ([] !== $actionItems || 'high' === $urgency || [] !== $matchedContacts || [] !== $matchedProperties) {
            return 'follow_up_required';
        }

        return 'follow_up_required';
    }

    /**
     * @param array<string, mixed> $summaryJson
     * @param list<array<string, mixed>> $matchedContacts
     * @param list<array<string, mixed>> $matchedProperties
     */
    private function determineNextStep(array $summaryJson, string $recommendedDisposition, array $matchedContacts, array $matchedProperties): string
    {
        $actionItems = $this->summaryArray($summaryJson, 'action_items');
        if ([] !== $actionItems) {
            return (string) $actionItems[0];
        }

        return match ($recommendedDisposition) {
            'spam' => 'Mark the call as spam and suppress future outreach.',
            'job_booked' => 'Create or confirm the job and send the appointment details.',
            'quote_requested' => 'Prepare and send a quote or estimate to the matched contact.',
            default => [] !== $matchedContacts || [] !== $matchedProperties
                ? 'Follow up with the matched contact and confirm the property details.'
                : 'Review the transcript and decide the next manual follow-up step.',
        };
    }

    /**
     * @param array<string, mixed> $summaryJson
     * @return list<string>
     */
    private function summaryArray(array $summaryJson, string $field): array
    {
        $value = $summaryJson[$field] ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $entry): string => trim((string) $entry),
            $value,
        )));
    }

    /**
     * @param array<string, mixed> $summaryJson
     */
    private function summaryString(array $summaryJson, string $field): string
    {
        $value = $summaryJson[$field] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function excerpt(string $text, int $limit = 220): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ('' === $text) {
            return '';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_strimwidth($text, 0, $limit - 1, '…');
    }

    /**
     * @return list<string>
     */
    private function extractPhonesFromText(string $text): array
    {
        preg_match_all('/(?:\+?1[\s.-]?)?(?:\(?\d{3}\)?[\s.-]?)\d{3}[\s.-]?\d{4}/', $text, $matches);
        if ([] === $matches[0]) {
            return [];
        }

        $phones = [];
        foreach ($matches[0] as $match) {
            $normalized = $this->normalizer->normalizePhoneOrNull($match);
            if (null !== $normalized) {
                $phones[] = $normalized;
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ('' !== $needle && str_contains($text, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function containsNormalized(string $haystack, string $needle): bool
    {
        return str_contains(mb_strtolower($haystack), mb_strtolower(trim($needle)));
    }

    private function propertyMatchesCorpus(Property $property, string $corpus): bool
    {
        foreach (array_filter([
            $property->getAddressLine1(),
            $property->getAddressLine2(),
            $property->getCity(),
            $property->getProvince(),
            $property->getPostalCode(),
        ]) as $needle) {
            if ($this->containsNormalized($corpus, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyMatch(Property $property, string $reason, string $confidence): array
    {
        return [
            'propertyId' => $property->getId(),
            'displayAddress' => $property->getDisplayAddress(),
            'reason' => $reason,
            'confidence' => $confidence,
        ];
    }

    /**
     * @param array<string, mixed> $summaryJson
     * @return string
     */
    private function buildCorpus(CallSession $session, array $summaryJson, string $summaryText, string $transcriptText): string
    {
        $parts = [
            $session->getInboundFrom() ?? '',
            $session->getInboundTo() ?? '',
            $summaryText,
            $transcriptText,
            $this->summaryString($summaryJson, 'customer_intent'),
            $this->summaryString($summaryJson, 'urgency'),
            $this->summaryString($summaryJson, 'sentiment'),
            implode(' ', $this->summaryArray($summaryJson, 'participants')),
            implode(' ', $this->summaryArray($summaryJson, 'equipment_mentions')),
            implode(' ', $this->summaryArray($summaryJson, 'appointment_mentions')),
            implode(' ', $this->summaryArray($summaryJson, 'quote_or_price_mentions')),
            implode(' ', $this->summaryArray($summaryJson, 'action_items')),
        ];

        return mb_strtolower(implode("\n", array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => '' !== $part))));
    }

    /**
     * @return array{
     *   summary:string,
     *   customer_intent:string,
     *   urgency:string,
     *   sentiment:string,
     *   recommended_disposition:string,
     *   next_step:string,
     *   equipment_mentions:list<string>,
     *   appointment_mentions:list<string>,
     *   quote_or_price_mentions:list<string>,
     *   matched_contacts:list<array<string, mixed>>,
     *   matched_properties:list<array<string, mixed>>,
     *   action_items:list<string>,
     *   evidence:list<string>
     * }
     */
    private function emptyInsights(): array
    {
        return [
            'summary' => '',
            'customer_intent' => '',
            'urgency' => '',
            'sentiment' => '',
            'recommended_disposition' => '',
            'next_step' => '',
            'equipment_mentions' => [],
            'appointment_mentions' => [],
            'quote_or_price_mentions' => [],
            'matched_contacts' => [],
            'matched_properties' => [],
            'action_items' => [],
            'evidence' => [],
        ];
    }
}
