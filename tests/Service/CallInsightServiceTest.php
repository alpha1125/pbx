<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Entity\CallSummary;
use App\Entity\CallTranscript;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\Tenant;
use App\Repository\CallSummaryLookupInterface;
use App\Repository\ContactRepository;
use App\Repository\PropertyContactRepository;
use App\Repository\PropertyRepository;
use App\Service\CallInsightService;
use App\Service\CrmInputNormalizer;
use PHPUnit\Framework\TestCase;

final class CallInsightServiceTest extends TestCase
{
    public function testBuildForTranscriptReturnsMatchedContactsPropertiesAndNextStep(): void
    {
        $tenant = $this->tenantWithId(200, 'Insight Tenant');
        $property = $this->propertyWithId($tenant, 300, '10 Insight Ave');
        $mentionedProperty = $this->propertyWithId($tenant, 301, '12 Transcript Way');
        $contact = $this->contactWithId($tenant, 400, 'Insight Contact', '+14165550123');
        $session = (new CallSession('session-100'))
            ->setTenant($tenant)
            ->setProperty($property)
            ->setContact($contact)
            ->setInboundTo('+14165550123')
            ->setFlowType(CallSession::FLOW_TYPE_CLICK_TO_CALL)
            ->setStatus('completed');
        $recording = new CallRecording($session, 'imported');
        $transcript = (new CallTranscript($recording, 'model-x', 'available'))
            ->setCallSession($session)
            ->setTranscriptText('We need an estimate for the furnace at 12 Transcript Way and will follow up tomorrow.');
        $summary = (new CallSummary($transcript))
            ->setStatus('available')
            ->setSummaryText('Insight Contact asked for an estimate and wants a callback tomorrow.')
            ->setSummaryJson([
                'summary' => 'Insight Contact asked for an estimate and wants a callback tomorrow.',
                'customer_intent' => 'Request an estimate and schedule follow-up.',
                'participants' => ['Insight Contact'],
                'equipment_mentions' => ['Furnace'],
                'appointment_mentions' => ['callback tomorrow'],
                'quote_or_price_mentions' => ['estimate'],
                'action_items' => ['Call Insight Contact back to confirm the estimate.'],
                'urgency' => 'high',
                'sentiment' => 'neutral',
                'recommended_disposition' => 'quote_requested',
                'next_step' => 'Call Insight Contact back to confirm the estimate.',
            ]);

        $summaryRepository = $this->createMock(CallSummaryLookupInterface::class);
        $summaryRepository->expects(self::once())
            ->method('findOneByTranscript')
            ->with($transcript)
            ->willReturn($summary);

        $contactRepository = $this->createMock(ContactRepository::class);
        $contactRepository->expects(self::once())
            ->method('findByTenant')
            ->with($tenant)
            ->willReturn([$contact]);
        $contactRepository->expects(self::once())
            ->method('findOneByTenantAndId')
            ->with($tenant, 400)
            ->willReturn($contact);

        $propertyRepository = $this->createMock(PropertyRepository::class);
        $propertyRepository->expects(self::once())
            ->method('findByTenant')
            ->with($tenant, 1, 500)
            ->willReturn([$property, $mentionedProperty]);

        $propertyContactRepository = $this->createMock(PropertyContactRepository::class);
        $propertyContactRepository->expects(self::once())
            ->method('findOneByTenantPropertyAndContact')
            ->willReturnOnConsecutiveCalls(
                (new PropertyContact($tenant, $property, $contact))->setIsPrimary(true),
            );

        $service = new CallInsightService(
            $summaryRepository,
            $contactRepository,
            $propertyRepository,
            $propertyContactRepository,
            new CrmInputNormalizer(),
        );

        $insights = $service->buildForTranscript($transcript);

        self::assertSame('Insight Contact asked for an estimate and wants a callback tomorrow.', $insights['summary']);
        self::assertSame('Request an estimate and schedule follow-up.', $insights['customer_intent']);
        self::assertSame('high', $insights['urgency']);
        self::assertSame('neutral', $insights['sentiment']);
        self::assertSame('quote_requested', $insights['recommended_disposition']);
        self::assertSame('Call Insight Contact back to confirm the estimate.', $insights['next_step']);
        self::assertCount(1, $insights['matched_contacts']);
        self::assertSame('Insight Contact', $insights['matched_contacts'][0]['displayName']);
        self::assertSame('high', $insights['matched_contacts'][0]['confidence']);
        self::assertCount(2, $insights['matched_properties']);
        self::assertSame('Linked property from the call session.', $insights['matched_properties'][0]['reason']);
        self::assertContains($insights['matched_properties'][1]['reason'], [
            'Linked through matched contact Insight Contact.',
            'Matched property address or postal code in the transcript.',
        ]);
        self::assertSame(['Call Insight Contact back to confirm the estimate.'], $insights['action_items']);
        self::assertNotEmpty($insights['evidence']);
    }

    private function tenantWithId(int $id, string $name): Tenant
    {
        $tenant = new Tenant($name);
        $this->setId($tenant, $id);

        return $tenant;
    }

    private function propertyWithId(Tenant $tenant, int $id, string $addressLine1): Property
    {
        $property = new Property($tenant, $addressLine1, 'Toronto', 'ON', 'M1M1M1');
        $this->setId($property, $id);

        return $property;
    }

    private function contactWithId(Tenant $tenant, int $id, string $displayName, string $phone): Contact
    {
        $contact = (new Contact($tenant, $displayName))
            ->setPrimaryPhone($phone);
        $this->setId($contact, $id);

        return $contact;
    }

    private function setId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity, 'id');
        $reflection->setValue($entity, $id);
    }
}
