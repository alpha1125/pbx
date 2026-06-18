<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\CsrPlaybookAttachment;
use App\Entity\Property;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use App\Service\CsrPlaybookEngineService;
use PHPUnit\Framework\TestCase;

final class CsrPlaybookEngineServiceTest extends TestCase
{
    public function testAllReturnsFixedTemplates(): void
    {
        $service = new CsrPlaybookEngineService();

        $playbooks = $service->all();

        self::assertCount(5, $playbooks);
        self::assertSame('Maintenance Offer', $playbooks[0]['title']);
        self::assertSame(CsrPlaybookAttachment::TYPE_DORMANT_CUSTOMER_OUTREACH, $playbooks[4]['type']);
    }

    public function testRecommendedOrderingUsesOpportunitySignals(): void
    {
        $tenant = new Tenant('Tenant A');
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $openInvoiceOpportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_OPEN_INVOICE,
            'property:1:open_invoice',
            'Outstanding invoice.',
        );
        $maintenanceOpportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_MAINTENANCE_PLAN_MISSING,
            'property:1:maintenance_plan_missing',
            'No active maintenance plan.',
        );

        $service = new CsrPlaybookEngineService();
        $recommended = $service->getRecommendedPlaybookTypes([$openInvoiceOpportunity, $maintenanceOpportunity]);

        self::assertSame([
            CsrPlaybookAttachment::TYPE_OVERDUE_INVOICE_DISCUSSION,
            CsrPlaybookAttachment::TYPE_MAINTENANCE_OFFER,
            CsrPlaybookAttachment::TYPE_WARRANTY_DISCUSSION,
            CsrPlaybookAttachment::TYPE_REPLACEMENT_DISCUSSION,
            CsrPlaybookAttachment::TYPE_DORMANT_CUSTOMER_OUTREACH,
        ], $recommended);
    }
}
