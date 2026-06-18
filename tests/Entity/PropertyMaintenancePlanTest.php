<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MaintenancePlan;
use App\Entity\Property;
use App\Entity\PropertyMaintenancePlan;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

final class PropertyMaintenancePlanTest extends TestCase
{
    public function testCreateAssignment(): void
    {
        $tenant = new Tenant('Test Tenant');
        $property = new Property($tenant, '10 Test St', 'Toronto', 'ON', 'M1M1M1');
        $plan = new MaintenancePlan($tenant, 'Silver Plan');

        $assignment = new PropertyMaintenancePlan($tenant, $property, $plan);

        self::assertSame($tenant, $assignment->getTenant());
        self::assertSame($property, $assignment->getProperty());
        self::assertSame($plan, $assignment->getMaintenancePlan());
        self::assertSame('Silver Plan', $assignment->getPlanNameAtAssignment());
        self::assertFalse($assignment->isCancelled());
        self::assertNull($assignment->getCancellationDate());

        $snapshot = $assignment->getSnapshotAsArray();
        self::assertSame('Silver Plan', $snapshot['name']);
    }

    public function testCancelAssignment(): void
    {
        $tenant = new Tenant('Test Tenant');
        $property = new Property($tenant, '20 Oak Ave', 'Vancouver', 'BC', 'V5L1M1');
        $plan = new MaintenancePlan($tenant, 'Gold Plan');

        $assignment = new PropertyMaintenancePlan($tenant, $property, $plan);

        $now = new \DateTimeImmutable('2026-08-15 10:00:00');
        $assignment->cancel($now);

        self::assertTrue($assignment->isCancelled());
        self::assertSame($now, $assignment->getCancellationDate());
    }

    public function testSnapshotReflectsCurrentPlanState(): void
    {
        // The snapshot method returns the current plan values (not an immutable copy).
        // This is acceptable for Phase 10C since plans are not expected to change after assignment.
        $tenant = new Tenant('Test Tenant');
        $property = new Property($tenant, '30 Pine Rd', 'Calgary', 'AB', 'T2P1M1');
        $plan = new MaintenancePlan($tenant, 'Bronze Plan');
        $plan->setVisitFrequencyDays(90);

        $assignment = new PropertyMaintenancePlan($tenant, $property, $plan);

        $snapshot = $assignment->getSnapshotAsArray();
        self::assertSame(90, $snapshot['visitFrequencyDays']);
        self::assertSame('Bronze Plan', $snapshot['name']);

        // Snapshot also reflects plan changes (expected behavior)
        $plan->setVisitFrequencyDays(30);
        $snapshot2 = $assignment->getSnapshotAsArray();
        self::assertSame(30, $snapshot2['visitFrequencyDays']);
    }

    public function testPlanNameAtAssignmentIsTrimmed(): void
    {
        $tenant = new Tenant('Test Tenant');
        $property = new Property($tenant, '40 Elm Dr', 'Edmonton', 'AB', 'T5J1M1');
        $plan = new MaintenancePlan($tenant, '  Silver Plan  ');

        $assignment = new PropertyMaintenancePlan($tenant, $property, $plan);

        self::assertSame('Silver Plan', $assignment->getPlanNameAtAssignment());
    }
}
