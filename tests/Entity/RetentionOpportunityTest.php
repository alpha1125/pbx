<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Property;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

final class RetentionOpportunityTest extends TestCase
{
    public function testCreatesOpenOpportunityWithLabels(): void
    {
        $tenant = new Tenant('Tenant A');
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $opportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_NO_RECENT_SERVICE,
            'property:1:no_recent_service',
            'No completed jobs on record for this property.',
        );

        self::assertTrue($opportunity->isOpen());
        self::assertSame('Open', $opportunity->getStatusLabel());
        self::assertSame('No recent service', $opportunity->getOpportunityTypeLabel());
        self::assertSame('property:1:no_recent_service', $opportunity->getSourceKey());
        self::assertNotNull($opportunity->getDetectedAt());
    }

    public function testStatusTransitions(): void
    {
        $tenant = new Tenant('Tenant A');
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $opportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_OPEN_INVOICE,
            'property:1:open_invoice',
            'Open invoice exists.',
        );

        $opportunity->markReviewed();
        self::assertSame(RetentionOpportunity::STATUS_REVIEWED, $opportunity->getStatus());

        $opportunity->dismiss();
        self::assertSame(RetentionOpportunity::STATUS_DISMISSED, $opportunity->getStatus());

        $opportunity->convert();
        self::assertSame(RetentionOpportunity::STATUS_CONVERTED, $opportunity->getStatus());
    }

    public function testSetterNormalization(): void
    {
        $tenant = new Tenant('Tenant A');
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $opportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_DORMANT_CUSTOMER,
            'property:1:dormant_customer',
            'Dormant customer.',
        );

        $opportunity->setSourceKey('  custom-source  ');
        $opportunity->setDetectedReason('  Reason text  ');
        $opportunity->setStatus('  reviewed  ');

        self::assertSame('custom-source', $opportunity->getSourceKey());
        self::assertSame('Reason text', $opportunity->getDetectedReason());
        self::assertSame('reviewed', $opportunity->getStatus());
    }

    public function testOpportunityAndStatusKeys(): void
    {
        $tenant = new Tenant('Tenant A');
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $opportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_OPEN_INVOICE,
            'property:1:open_invoice',
            'Open invoice exists.',
        );

        self::assertContains(RetentionOpportunity::TYPE_MAINTENANCE_PLAN_MISSING, $opportunity->getOpportunityTypeKeys());
        self::assertContains(RetentionOpportunity::STATUS_CONVERTED, $opportunity->getStatusKeys());
    }
}
