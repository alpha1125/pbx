<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Rfq;
use App\Repository\RfqRepository;
use App\Service\AuditLogger;
use App\Service\RfqIntakeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RfqIntakeWorkflowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateDatabase();
        $this->client->disableReboot();
    }

    public function testHomeownerIntakeCreatesASubmittedRfqAndAuditLog(): void
    {
        $service = $this->createService();
        $rfq = (new Rfq('100 Intake Street', 'Toronto', 'ON', 'M5V2T6'))
            ->setExternalReference('TP-RFQ-2001')
            ->setCustomerName('Taylor Homeowner')
            ->setCustomerPhone('+14165550111')
            ->setCustomerEmail('taylor@example.com')
            ->setProjectType('heat_pump_replacement')
            ->setDescription('Looking for a replacement heat pump.');

        $saved = $service->intakeHomeownerRfq($rfq);

        self::assertSame($rfq, $saved);
        self::assertNotNull($saved->getId());
        self::assertSame(Rfq::STATUS_SUBMITTED, $saved->getStatus());

        $this->entityManager->clear();
        $rfqRepository = static::getContainer()->get(RfqRepository::class);
        $persisted = $rfqRepository->findOneByExternalReference('TP-RFQ-2001');

        self::assertInstanceOf(Rfq::class, $persisted);
        self::assertSame(Rfq::STATUS_SUBMITTED, $persisted->getStatus());
        self::assertSame('Taylor Homeowner', $persisted->getCustomerName());

        $auditCount = (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE entity_type = \'rfq\' AND action = \'rfq.submitted\'');
        self::assertSame(1, $auditCount);
    }

    public function testAdminIntakeReturnsAnExistingDuplicateRfq(): void
    {
        $service = $this->createService();

        $original = (new Rfq('200 Duplicate Ave', 'Toronto', 'ON', 'M5V2T6'))
            ->setCustomerName('Morgan Homeowner')
            ->setCustomerPhone('+14165550112')
            ->setCustomerEmail('morgan@example.com')
            ->setProjectType('furnace_replacement')
            ->setDescription('Need a new furnace installed.');
        $saved = $service->intakeHomeownerRfq($original);

        $duplicate = (new Rfq('200 Duplicate Ave', 'Toronto', 'ON', 'M5V2T6'))
            ->setCustomerName('Morgan Homeowner')
            ->setCustomerPhone('+14165550112')
            ->setCustomerEmail('morgan@example.com')
            ->setProjectType('furnace_replacement')
            ->setDescription('Need a new furnace installed again.');

        $matched = $service->intakeAdminRfq($duplicate);

        self::assertSame($saved->getId(), $matched->getId());
        self::assertSame($saved->getExternalReference(), $matched->getExternalReference());
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM rfq'));
    }

    public function testIntakeRejectsInvalidRfq(): void
    {
        $service = $this->createService();
        $rfq = new Rfq('', '', '', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RFQ validation failed:');

        $service->intakeHomeownerRfq($rfq);
    }

    private function truncateDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        $platformClass = $connection->getDatabasePlatform()::class;
        if (!str_contains($platformClass, 'PostgreSQL')) {
            return;
        }

        $tables = array_filter(
            $connection->createSchemaManager()->listTableNames(),
            static fn (string $table): bool => 'doctrine_migration_versions' !== $table,
        );

        if ([] === $tables) {
            return;
        }

        $connection->executeStatement('TRUNCATE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
    }

    private function createService(): RfqIntakeService
    {
        return new RfqIntakeService(
            $this->entityManager,
            static::getContainer()->get(RfqRepository::class),
            static::getContainer()->get(ValidatorInterface::class),
            static::getContainer()->get(AuditLogger::class),
        );
    }
}
