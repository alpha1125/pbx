<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Contact;
use App\Entity\Equipment;
use App\Entity\Invoice;
use App\Entity\Job;
use App\Entity\Property;
use App\Entity\Quote;
use App\Entity\Task;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

final class JobTaskModelTest extends TestCase
{
    public function testJobAndTaskDefaultsAndLinks(): void
    {
        $tenant = new Tenant('Tenant One');
        $property = new Property($tenant, '10 Test St', 'Toronto', 'ON', 'M1M1M1');
        $contact = new Contact($tenant, 'Tenant Contact');
        $quote = new Quote($tenant, $property, 'Q-1');
        $invoice = new Invoice($tenant, $property, 'I-1');
        $equipment = new Equipment($tenant, $property, Equipment::TYPE_FURNACE);

        $job = (new Job($tenant, $property))
            ->setContact($contact)
            ->setQuote($quote)
            ->setInvoice($invoice)
            ->setEquipment($equipment)
            ->setTitle('Replace furnace')
            ->setUnresolvedIssueNotes('Need to quote replacement blower motor')
            ->setServiceReminderAt(new \DateTimeImmutable('2026-07-20 09:30:00'))
            ->setServiceReminderNotes('Schedule summer maintenance follow-up')
            ->setScheduledStartAt(new \DateTimeImmutable('2026-06-20 09:00:00'))
            ->setScheduledEndAt(new \DateTimeImmutable('2026-06-20 12:00:00'));

        $task = (new Task($tenant, $job, 'Order parts'))
            ->setDescription('Confirm filter and igniter stock')
            ->setStatus(Task::STATUS_SCHEDULED);

        self::assertSame(Job::STATUS_UNSCHEDULED, $job->getStatus());
        self::assertSame('Unscheduled', $job->getStatusLabel());
        self::assertSame($contact, $job->getContact());
        self::assertSame($quote, $job->getQuote());
        self::assertSame($invoice, $job->getInvoice());
        self::assertSame($equipment, $job->getEquipment());
        self::assertSame('Replace furnace', $job->getTitle());
        self::assertSame('Need to quote replacement blower motor', $job->getUnresolvedIssueNotes());
        self::assertSame('2026-07-20 09:30:00', $job->getServiceReminderAt()?->format('Y-m-d H:i:s'));
        self::assertSame('Schedule summer maintenance follow-up', $job->getServiceReminderNotes());
        self::assertNotNull($job->getScheduledStartAt());

        self::assertSame($tenant, $task->getTenant());
        self::assertSame($job, $task->getJob());
        self::assertSame('Order parts', $task->getTitle());
        self::assertSame(Task::STATUS_SCHEDULED, $task->getStatus());
        self::assertSame('Scheduled', $task->getStatusLabel());
        self::assertSame('Confirm filter and igniter stock', $task->getDescription());
    }
}
