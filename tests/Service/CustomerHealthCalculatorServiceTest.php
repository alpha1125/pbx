<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Property;
use App\Entity\Tenant;
use App\Service\CustomerHealthCalculatorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class CustomerHealthCalculatorServiceTest extends TestCase
{
    public function testServiceCanBeInstantiated(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $service = new CustomerHealthCalculatorService($em);
        self::assertInstanceOf(CustomerHealthCalculatorService::class, $service);
    }

    public function testCategoryConstantsAreCorrect(): void
    {
        self::assertSame('healthy', CustomerHealthCalculatorService::CATEGORY_HEALTHY);
        self::assertSame('needs_attention', CustomerHealthCalculatorService::CATEGORY_NEEDS_ATTENTION);
        self::assertSame('at_risk', CustomerHealthCalculatorService::CATEGORY_AT_RISK);
        self::assertSame('dormant', CustomerHealthCalculatorService::CATEGORY_DORMANT);
        self::assertSame('lost', CustomerHealthCalculatorService::CATEGORY_LOST);
    }

    public function testDefaultThresholds(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $service = new CustomerHealthCalculatorService($em);
        self::assertSame(365, $service->getMaxDaysSinceLastJob());
        self::assertSame(180, $service->getMaxDaysSinceLastCall());
        self::assertSame(80, $service->getHealthyScoreMin());
    }

    public function testThresholdTuners(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $service = new CustomerHealthCalculatorService($em);
        $service->setMaxDaysSinceLastJob(200);
        self::assertSame(200, $service->getMaxDaysSinceLastJob());
        $service->setMaxDaysSinceLastCall(120);
        self::assertSame(120, $service->getMaxDaysSinceLastCall());
        $service->setHealthyScoreMin(-10);
        self::assertSame(0, $service->getHealthyScoreMin());
        $service->setHealthyScoreMin(200);
        self::assertSame(100, $service->getHealthyScoreMin());
        $service->setHealthyScoreMin(75);
        self::assertSame(75, $service->getHealthyScoreMin());
    }
}
