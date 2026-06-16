<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Invoice;
use App\Entity\Quote;
use App\Entity\QuoteLineItem;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Repository\QuoteLineItemRepository;
use App\Service\AuditLogger;
use App\Service\DocumentNumberGenerator;
use App\Service\InvoiceTimelineProjectorInterface;
use App\Service\QuoteToInvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class QuoteToInvoiceServiceTest extends TestCase
{
    public function testConvertAppliesTenantInvoiceDefaults(): void
    {
        $tenant = (new Tenant('Tenant One'))
            ->setInvoiceDueDays(45)
            ->setInvoicePaymentInstructions('Send payment within 45 days.')
            ->setInvoiceFooter('Thank you for your business.');
        $property = new Property($tenant, '10 Test St', 'Toronto', 'ON', 'M1M1M1');
        $quote = (new Quote($tenant, $property, 'Q-1'))->setStatus(Quote::STATUS_ACCEPTED);
        $lineItem = (new QuoteLineItem($tenant, $quote, 'Replace filter'))
            ->setQuantity('1.00')
            ->setUnitPriceCents(5000)
            ->setTotalCents(5000)
            ->setSortOrder(1);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $func) => $func());
        $entityManager->expects(self::exactly(2))->method('persist');

        $quoteLineItemRepository = $this->createMock(QuoteLineItemRepository::class);
        $quoteLineItemRepository->expects(self::once())->method('findByQuote')->with($quote)->willReturn([$lineItem]);

        $documentNumberGenerator = $this->createMock(DocumentNumberGenerator::class);
        $documentNumberGenerator->expects(self::once())->method('generateInvoiceNumber')->with($tenant)->willReturn('I-1');

        $auditLogger = $this->createStub(AuditLogger::class);
        $timelineProjector = $this->createStub(InvoiceTimelineProjectorInterface::class);

        $service = new QuoteToInvoiceService(
            $entityManager,
            $quoteLineItemRepository,
            $documentNumberGenerator,
            $auditLogger,
            $timelineProjector,
        );

        $invoice = $service->convert($quote);

        self::assertSame(Invoice::STATUS_UNPAID, $invoice->getStatus());
        self::assertSame(45, $invoice->getTenant()->getInvoiceDueDays());
        self::assertSame('Send payment within 45 days.', $invoice->getPaymentInstructions());
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $invoice->getIssuedAt()?->format('Y-m-d'));
        self::assertSame((new \DateTimeImmutable('+45 days'))->format('Y-m-d'), $invoice->getDueAt()?->format('Y-m-d'));
    }
}
