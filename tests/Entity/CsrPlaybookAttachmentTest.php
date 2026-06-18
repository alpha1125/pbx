<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Contact;
use App\Entity\CsrPlaybookAttachment;
use App\Entity\Property;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

final class CsrPlaybookAttachmentTest extends TestCase
{
    public function testCreatesAttachmentWithLabelsAndContexts(): void
    {
        $tenant = new Tenant('Tenant A');
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $contact = new Contact($tenant, 'Primary Contact');
        $opportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_OPEN_INVOICE,
            'property:1:open_invoice',
            'Outstanding invoice.',
        );

        $attachment = new CsrPlaybookAttachment(
            $tenant,
            CsrPlaybookAttachment::TYPE_OVERDUE_INVOICE_DISCUSSION,
            $property,
            $contact,
            $opportunity,
        );

        self::assertSame($tenant, $attachment->getTenant());
        self::assertSame($property, $attachment->getProperty());
        self::assertSame($contact, $attachment->getContact());
        self::assertSame($opportunity, $attachment->getRetentionOpportunity());
        self::assertSame(CsrPlaybookAttachment::TYPE_OVERDUE_INVOICE_DISCUSSION, $attachment->getPlaybookType());
        self::assertSame('Overdue Invoice Discussion', $attachment->getPlaybookTypeLabel());
        self::assertTrue($attachment->hasPropertyContext());
        self::assertTrue($attachment->hasContactContext());
        self::assertTrue($attachment->hasOpportunityContext());
    }

    public function testSetterNormalization(): void
    {
        $tenant = new Tenant('Tenant A');
        $attachment = new CsrPlaybookAttachment($tenant, '  maintenance_offer  ');

        $attachment->setPlaybookType('  dormant_customer_outreach  ');

        self::assertSame('dormant_customer_outreach', $attachment->getPlaybookType());
        self::assertContains(CsrPlaybookAttachment::TYPE_MAINTENANCE_OFFER, CsrPlaybookAttachment::getPlaybookTypeKeys());
    }
}
