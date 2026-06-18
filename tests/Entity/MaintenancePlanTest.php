<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MaintenancePlan;
use App\Entity\Property;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

final class MaintenancePlanTest extends TestCase
{
    public function testCreateValidEntity(): void
    {
        $tenant = new Tenant('Test Tenant');
        $plan = new MaintenancePlan($tenant, 'Basic HVAC Checkup');
        self::assertNotEmpty($plan->getName());
        self::assertSame(18, strlen($plan->getName()));
        self::assertFalse($plan->isActive());
        self::assertInstanceOf(\DateTimeImmutable::class, $plan->getCreatedAt());

        self::assertSame(MaintenancePlan::PLAN_BRONZE, $plan->getPlanType());
        self::assertSame(180, $plan->getVisitFrequencyDays());
        self::assertSame(0, $plan->getDiscountPercentage());
        self::assertFalse($plan->isPriorityScheduling());
        self::assertSame([], $plan->getIncludedServices());
    }

    public function testPlanTypeLabels(): void
    {
        $tenant = new Tenant('Test Tenant');

        $bronze = new MaintenancePlan($tenant, 'Bronze Plan');
        $bronze->setPlanType(MaintenancePlan::PLAN_BRONZE);
        self::assertSame('Bronze', $bronze->getPlanTypeLabel());

        $silver = new MaintenancePlan($tenant, 'Silver Plan');
        $silver->setPlanType(MaintenancePlan::PLAN_SILVER);
        self::assertSame('Silver', $silver->getPlanTypeLabel());

        $gold = new MaintenancePlan($tenant, 'Gold Plan');
        $gold->setPlanType(MaintenancePlan::PLAN_GOLD);
        self::assertSame('Gold', $gold->getPlanTypeLabel());
    }

    public function testSettersWithBoundaryClamping(): void
    {
        $tenant = new Tenant('Test Tenant');
        $plan = new MaintenancePlan($tenant, 'Test Plan');

        $plan->setVisitFrequencyDays(1);
        self::assertSame(7, $plan->getVisitFrequencyDays());

        $plan->setDiscountPercentage(-5);
        self::assertSame(0, $plan->getDiscountPercentage());

        $plan->setDiscountPercentage(150);
        self::assertSame(100, $plan->getDiscountPercentage());

        $plan->setPriorityScheduling(true);
        self::assertTrue($plan->isPriorityScheduling());

        $dates = new \DateTimeImmutable('2026-07-01');
        $plan->setStartDate($dates);
        self::assertSame($dates, $plan->getStartDate());

        $plan->setName('  Spaced  ');
        self::assertSame('Spaced', $plan->getName());
    }

    public function testActiveInactiveLifecycle(): void
    {
        $tenant = new Tenant('Test Tenant');
        $plan = new MaintenancePlan($tenant, 'Draft Plan');
        self::assertFalse($plan->isActive());

        $plan->activate();
        self::assertTrue($plan->isActive());

        $plan->deactivate();
        self::assertFalse($plan->isActive());
    }

    public function testPlanTypeKeys(): void
    {
        $tenant = new Tenant('Test Tenant');
        $plan = new MaintenancePlan($tenant, 'Test Plan');
        self::assertSame(['bronze', 'silver', 'gold'], $plan->getPlanTypeKeys());
    }

    public function testIncludedServicesNormalization(): void
    {
        $tenant = new Tenant('Test Tenant');
        $plan = new MaintenancePlan($tenant, 'Test Plan');

        $services = ['  Filter Replacement  ', '  Thermostat Check', '', 'Hvac Inspection'];
        $plan->setIncludedServices($services);

        self::assertSame(3, count($plan->getIncludedServices()));
        self::assertSame('Filter Replacement', $plan->getIncludedServices()[0]);
    }
}
