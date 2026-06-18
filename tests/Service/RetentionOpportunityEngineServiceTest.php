<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Contact;
use App\Entity\Equipment;
use App\Entity\Invoice;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\PropertyMaintenancePlan;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use App\Repository\CallSessionRepository;
use App\Repository\EquipmentRepository;
use App\Repository\InvoiceRepository;
use App\Repository\JobRepository;
use App\Repository\PropertyContactRepository;
use App\Repository\PropertyMaintenancePlanRepository;
use App\Repository\RetentionOpportunityRepository;
use App\Service\CustomerHealthCalculatorService;
use App\Service\PropertyHealthCalculatorInterface;
use App\Service\RetentionOpportunityEngineService;
use PHPUnit\Framework\TestCase;

final class RetentionOpportunityEngineServiceTest extends TestCase
{
    public function testGenerateForPropertyBuildsDeterministicOpportunities(): void
    {
        $tenant = new Tenant('Tenant A');
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $contact = new Contact($tenant, 'Primary Contact');
        $equipment = new Equipment($tenant, $property, Equipment::TYPE_FURNACE);
        $equipment->setInstalledAt(new \DateTimeImmutable('2010-01-01'));
        $equipment->setWarrantyExpiresAt(new \DateTimeImmutable('2026-07-01'));

        $propertyContactRepository = $this->createStub(PropertyContactRepository::class);
        $propertyContact = $this->createStub(PropertyContact::class);
        $propertyContact->method('getContact')->willReturn($contact);
        $propertyContactRepository->method('findPrimaryByProperty')->willReturn($propertyContact);

        $equipmentRepository = $this->createStub(EquipmentRepository::class);
        $equipmentRepository->method('findByProperty')->willReturn([$equipment]);

        $invoice = new Invoice($tenant, $property, 'INV-1001');
        $invoice->setStatus(Invoice::STATUS_SENT);
        $invoice->setTotalCents(125000);
        $invoice->setAmountPaidCents(25000);
        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $invoiceRepository->method('findByProperty')->willReturn([$invoice]);

        $jobRepository = $this->createStub(JobRepository::class);
        $jobRepository->method('findLatestCompletedAtByProperty')->willReturn(new \DateTimeImmutable('-400 days'));

        $callSessionRepository = $this->createStub(CallSessionRepository::class);
        $callSessionRepository->method('findLatestUpdatedAtByProperty')->willReturn(new \DateTimeImmutable('-200 days'));

        $maintenancePlanRepository = $this->createStub(PropertyMaintenancePlanRepository::class);
        $maintenancePlanRepository->method('findByProperty')->willReturn([]);

        $healthCalculator = $this->createStub(PropertyHealthCalculatorInterface::class);
        $healthCalculator->method('calculate')->willReturn([
            'score' => 42,
            'category' => CustomerHealthCalculatorService::CATEGORY_DORMANT,
            'factors' => [],
        ]);

        $retentionOpportunityRepository = $this->createStub(RetentionOpportunityRepository::class);
        $retentionOpportunityRepository->method('findOpenByTenantPropertyTypeAndSourceKey')->willReturn(null);

        $service = new RetentionOpportunityEngineService(
            $propertyContactRepository,
            $equipmentRepository,
            $invoiceRepository,
            $jobRepository,
            $callSessionRepository,
            $maintenancePlanRepository,
            $healthCalculator,
            $retentionOpportunityRepository,
        );

        $result = $service->generateForProperty($property);

        self::assertCount(7, $result['created']);
        self::assertCount(0, $result['updated']);
        self::assertContainsOnlyInstancesOf(RetentionOpportunity::class, $result['created']);

        $types = array_map(static fn (RetentionOpportunity $opportunity): string => $opportunity->getOpportunityType(), $result['created']);
        self::assertContains(RetentionOpportunity::TYPE_NO_RECENT_SERVICE, $types);
        self::assertContains(RetentionOpportunity::TYPE_NO_RECENT_CALLS, $types);
        self::assertContains(RetentionOpportunity::TYPE_OLD_EQUIPMENT, $types);
        self::assertContains(RetentionOpportunity::TYPE_WARRANTY_NEARING_EXPIRATION, $types);
        self::assertContains(RetentionOpportunity::TYPE_DORMANT_CUSTOMER, $types);
        self::assertContains(RetentionOpportunity::TYPE_OPEN_INVOICE, $types);
        self::assertContains(RetentionOpportunity::TYPE_MAINTENANCE_PLAN_MISSING, $types);
    }

    public function testGenerateForPropertyReusesExistingOpenOpportunity(): void
    {
        $tenant = new Tenant('Tenant A');
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $contact = new Contact($tenant, 'Primary Contact');

        $propertyContactRepository = $this->createStub(PropertyContactRepository::class);
        $propertyContact = $this->createStub(PropertyContact::class);
        $propertyContact->method('getContact')->willReturn($contact);
        $propertyContactRepository->method('findPrimaryByProperty')->willReturn($propertyContact);

        $equipmentRepository = $this->createStub(EquipmentRepository::class);
        $equipmentRepository->method('findByProperty')->willReturn([]);

        $invoiceRepository = $this->createStub(InvoiceRepository::class);
        $invoiceRepository->method('findByProperty')->willReturn([]);

        $jobRepository = $this->createStub(JobRepository::class);
        $jobRepository->method('findLatestCompletedAtByProperty')->willReturn(new \DateTimeImmutable('-400 days'));

        $callSessionRepository = $this->createStub(CallSessionRepository::class);
        $callSessionRepository->method('findLatestUpdatedAtByProperty')->willReturn(new \DateTimeImmutable('-200 days'));

        $maintenancePlanRepository = $this->createStub(PropertyMaintenancePlanRepository::class);
        $maintenancePlanRepository->method('findByProperty')->willReturn([]);

        $healthCalculator = $this->createStub(PropertyHealthCalculatorInterface::class);
        $healthCalculator->method('calculate')->willReturn([
            'score' => 42,
            'category' => CustomerHealthCalculatorService::CATEGORY_DORMANT,
            'factors' => [],
        ]);

        $existing = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_DORMANT_CUSTOMER,
            'property:0:dormant_customer',
            'Old reason',
            $contact,
            null,
            new \DateTimeImmutable('-2 days'),
        );

        $retentionOpportunityRepository = $this->createStub(RetentionOpportunityRepository::class);
        $retentionOpportunityRepository->method('findOpenByTenantPropertyTypeAndSourceKey')->willReturnCallback(
            static function (Tenant $tenantArg, Property $propertyArg, string $typeArg, string $sourceKeyArg) use ($existing): ?RetentionOpportunity {
                if (RetentionOpportunity::TYPE_DORMANT_CUSTOMER === $typeArg) {
                    return $existing;
                }

                return null;
            },
        );

        $service = new RetentionOpportunityEngineService(
            $propertyContactRepository,
            $equipmentRepository,
            $invoiceRepository,
            $jobRepository,
            $callSessionRepository,
            $maintenancePlanRepository,
            $healthCalculator,
            $retentionOpportunityRepository,
        );

        $result = $service->generateForProperty($property);

        self::assertCount(3, $result['created']);
        self::assertCount(1, $result['updated']);
        self::assertSame('Health score is 42 and category is dormant.', $existing->getDetectedReason());
    }
}
