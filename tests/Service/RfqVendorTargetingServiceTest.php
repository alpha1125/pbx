<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Rfq;
use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\RfqVendorTargetingService;
use PHPUnit\Framework\TestCase;

final class RfqVendorTargetingServiceTest extends TestCase
{
    public function testFindEligibleTenantsRanksSpecificMatchesAheadOfBroadMatches(): void
    {
        $rfq = (new Rfq('100 Intake Street', 'Toronto', 'ON', 'M5V 2T6'))
            ->setCountry('CA');

        $postalMatch = (new Tenant('Postal Match'))
            ->setRfqVendorEnabled(true)
            ->setRfqServiceAreaPostalPrefixes(['m5v']);

        $provinceMatch = (new Tenant('Province Match'))
            ->setRfqVendorEnabled(true)
            ->setRfqServiceAreaProvinces(['on']);

        $broadMatch = (new Tenant('Broad Match'))
            ->setRfqVendorEnabled(true);

        $disabled = (new Tenant('Disabled Vendor'))
            ->setRfqVendorEnabled(false);

        $service = new RfqVendorTargetingService($this->createStub(TenantRepository::class));
        $eligible = $service->findEligibleTenants($rfq, [$broadMatch, $disabled, $provinceMatch, $postalMatch]);

        self::assertSame([$postalMatch, $provinceMatch, $broadMatch], $eligible);
        self::assertSame(100, $service->scoreTenant($postalMatch, $rfq));
        self::assertSame(25, $service->scoreTenant($provinceMatch, $rfq));
        self::assertSame(0, $service->scoreTenant($broadMatch, $rfq));
        self::assertSame(-1, $service->scoreTenant($disabled, $rfq));
    }

    public function testTenantOutsideConfiguredServiceAreaIsNotEligible(): void
    {
        $rfq = (new Rfq('100 Intake Street', 'Toronto', 'ON', 'M5V2T6'))
            ->setCountry('CA');

        $tenant = (new Tenant('Calgary Only'))
            ->setRfqVendorEnabled(true)
            ->setRfqServiceAreaProvinces(['AB'])
            ->setRfqServiceAreaCities(['Calgary']);

        $service = new RfqVendorTargetingService($this->createStub(TenantRepository::class));

        self::assertFalse($service->isEligibleTenant($rfq, $tenant));
        self::assertSame(-1, $service->scoreTenant($tenant, $rfq));
        self::assertSame([], $service->findEligibleTenants($rfq, [$tenant]));
    }
}
