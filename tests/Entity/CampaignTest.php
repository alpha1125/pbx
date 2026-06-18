<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Campaign;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

final class CampaignTest extends TestCase
{
    public function testCreateCampaignDefaultsAndLabels(): void
    {
        $tenant = new Tenant('Tenant A');
        $campaign = new Campaign($tenant, 'Spring Service Drive');

        self::assertSame($tenant, $campaign->getTenant());
        self::assertSame('Spring Service Drive', $campaign->getName());
        self::assertSame(Campaign::TYPE_SPRING_AC_TUNE_UP, $campaign->getCampaignType());
        self::assertSame(Campaign::STATUS_DRAFT, $campaign->getStatus());
        self::assertSame('', $campaign->getAudienceDescription());
        self::assertNull($campaign->getScheduledDate());
        self::assertNull($campaign->getNotes());
        self::assertSame('Spring AC Tune-Up', $campaign->getCampaignTypeLabel());
        self::assertSame('Draft', $campaign->getStatusLabel());
    }

    public function testSettersNormalizeValues(): void
    {
        $tenant = new Tenant('Tenant A');
        $campaign = new Campaign($tenant, '  Spring Service Drive  ');

        $scheduledDate = new \DateTimeImmutable('2026-07-01');
        $campaign
            ->setName('  Summer Tune-Up  ')
            ->setCampaignType(Campaign::TYPE_FALL_FURNACE_INSPECTION)
            ->setAudienceDescription('  Homes with aging furnaces  ')
            ->setScheduledDate($scheduledDate)
            ->setStatus(Campaign::STATUS_SCHEDULED)
            ->setNotes('  Follow up with dispatch  ');

        self::assertSame('Summer Tune-Up', $campaign->getName());
        self::assertSame(Campaign::TYPE_FALL_FURNACE_INSPECTION, $campaign->getCampaignType());
        self::assertSame('Homes with aging furnaces', $campaign->getAudienceDescription());
        self::assertSame($scheduledDate, $campaign->getScheduledDate());
        self::assertSame(Campaign::STATUS_SCHEDULED, $campaign->getStatus());
        self::assertSame('Follow up with dispatch', $campaign->getNotes());
    }

    public function testChoiceKeysIncludeRequiredValues(): void
    {
        self::assertContains(Campaign::TYPE_MAINTENANCE_RENEWAL, Campaign::getCampaignTypeKeys());
        self::assertContains(Campaign::STATUS_CANCELLED, Campaign::getStatusKeys());
    }
}
