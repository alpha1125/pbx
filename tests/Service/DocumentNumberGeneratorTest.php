<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Repository\QuoteRepository;
use App\Service\DocumentNumberGenerator;
use PHPUnit\Framework\TestCase;

final class DocumentNumberGeneratorTest extends TestCase
{
    public function testGenerateQuoteNumberUsesTenantScopedSequence(): void
    {
        $quoteRepository = $this->createMock(QuoteRepository::class);
        $quoteRepository->expects(self::exactly(2))
            ->method('countByTenant')
            ->willReturn(12);

        $generator = new DocumentNumberGenerator($quoteRepository);
        $tenant = new Tenant('Acme HVAC');
        $this->setTenantId($tenant, 7);

        self::assertSame('Q-7-00013', $generator->generateQuoteNumber($tenant));
        self::assertSame('Q-7-00013-R2', $generator->generateQuoteNumber($tenant, 2));
    }

    private function setTenantId(Tenant $tenant, int $id): void
    {
        $reflection = new \ReflectionObject($tenant);
        $property = $reflection->getProperty('id');
        $property->setValue($tenant, $id);
    }
}
