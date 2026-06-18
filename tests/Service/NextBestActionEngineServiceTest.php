<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Contact;
use App\Entity\CustomerSentimentHistory;
use App\Entity\Equipment;
use App\Entity\EquipmentServiceRecord;
use App\Entity\Invoice;
use App\Entity\NextBestActionSuggestion;
use App\Entity\Property;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\NextBestActionSuggestionRepository;
use App\Service\NextBestActionEngineService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NextBestActionEngineServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateDatabase();
    }

    public function testGenerateForPropertyCreatesHumanApprovedSuggestions(): void
    {
        $data = $this->seedData();
        $service = static::getContainer()->get(NextBestActionEngineService::class);

        $result = $service->generateForProperty($data['property'], $this->entityManager);
        $this->entityManager->flush();

        self::assertCount(7, $result['created']);

        $repository = static::getContainer()->get(NextBestActionSuggestionRepository::class);
        $suggestions = $repository->findByTenantAndPropertyOrdered($data['tenant'], $data['property']);
        self::assertCount(7, $suggestions);

        $types = array_map(static fn (NextBestActionSuggestion $suggestion): string => $suggestion->getSuggestionType(), $suggestions);
        self::assertContains(NextBestActionSuggestion::TYPE_REVIEW_OVERDUE_INVOICE, $types);
        self::assertContains(NextBestActionSuggestion::TYPE_OFFER_MAINTENANCE_PLAN, $types);
        self::assertContains(NextBestActionSuggestion::TYPE_CALL_CUSTOMER, $types);
        self::assertContains(NextBestActionSuggestion::TYPE_BOOK_MAINTENANCE, $types);
        self::assertContains(NextBestActionSuggestion::TYPE_REPLACE_EQUIPMENT, $types);
        self::assertContains(NextBestActionSuggestion::TYPE_INSPECT_SYSTEM, $types);
        self::assertContains(NextBestActionSuggestion::TYPE_SCHEDULE_FOLLOW_UP, $types);

        $invoiceSuggestion = current(array_filter($suggestions, static fn (NextBestActionSuggestion $suggestion): bool => NextBestActionSuggestion::TYPE_REVIEW_OVERDUE_INVOICE === $suggestion->getSuggestionType()));
        self::assertInstanceOf(NextBestActionSuggestion::class, $invoiceSuggestion);
        self::assertSame(NextBestActionSuggestion::STATUS_SUGGESTED, $invoiceSuggestion->getStatus());
        self::assertSame(NextBestActionSuggestion::CONFIDENCE_HIGH, $invoiceSuggestion->getConfidence());
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

    /**
     * @return array{tenant: Tenant, property: Property}
     */
    private function seedData(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = (new Tenant('Tenant One '.$suffix))->setEmail(sprintf('nba-tenant-%s@example.com', $suffix));
        $user = (new User())->setEmail(sprintf('nba-user-%s@example.com', $suffix))->setPassword('unused')->setRoles(['ROLE_USER']);
        $property = new Property($tenant, '100 Main St '.$suffix, 'Toronto', 'ON', 'M1M1M1');
        $contact = new Contact($tenant, 'Primary Contact');
        $technician = (new User())->setEmail(sprintf('tech-%s@example.com', $suffix))->setPassword('unused')->setRoles(['ROLE_USER']);
        $equipment = (new Equipment($tenant, $property, Equipment::TYPE_FURNACE))
            ->setBrand('Acme')
            ->setModelNumber('X100')
            ->setInstalledAt(new \DateTimeImmutable('2010-01-01'))
            ->setWarrantyExpiresAt(new \DateTimeImmutable('2025-01-01'));
        $serviceRecord = (new EquipmentServiceRecord($tenant, $property))
            ->setEquipment($equipment)
            ->setTechnician($technician)
            ->setCompletedAt(new \DateTimeImmutable('2024-01-01'))
            ->setRecommendedRepairNotes('Cracked heat exchanger needs inspection.');
        $invoice = (new Invoice($tenant, $property, 'INV-1001-'.$suffix))
            ->setStatus(Invoice::STATUS_SENT)
            ->setTotalCents(150000)
            ->setAmountPaidCents(25000)
            ->setSentAt(new \DateTimeImmutable('2026-06-01 09:00:00'));
        $opportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_DORMANT_CUSTOMER,
            'dormant:'.$suffix,
            'Customer has not engaged recently.',
            $contact,
            null,
            new \DateTimeImmutable('2026-06-02 09:00:00'),
        );

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($technician);
        $this->entityManager->persist((new UserTenantMembership($user, $tenant))->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])->setIsDefault(true));
        $this->entityManager->persist($property);
        $this->entityManager->persist($contact);
        $this->entityManager->persist($equipment);
        $this->entityManager->persist($serviceRecord);
        $this->entityManager->persist($invoice);
        $this->entityManager->persist($opportunity);
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'property' => $property,
        ];
    }
}
