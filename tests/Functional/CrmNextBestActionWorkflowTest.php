<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Contact;
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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class CrmNextBestActionWorkflowTest extends WebTestCase
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

    public function testNextBestActionsCanBeGeneratedApprovedAndDismissed(): void
    {
        $data = $this->seedData();
        $this->client->loginUser($data['user']);
        $this->selectTenant($data['tenant']);

        $crawler = $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#next-best-actions-card', 'Next Best Actions');
        self::assertSelectorTextContains('#next-best-actions-card', 'No next best actions saved yet.');

        $generateToken = $crawler->filter(sprintf('form[action="/crm/properties/%d/next-best-actions/generate"] input[name="_token"]', $data['property']->getId()))->attr('value');
        $this->client->request('POST', '/crm/properties/'.$data['property']->getId().'/next-best-actions/generate', [
            '_token' => $generateToken,
        ]);
        self::assertResponseRedirects('/crm/properties/'.$data['property']->getId());

        $this->entityManager->clear();
        $repository = static::getContainer()->get(NextBestActionSuggestionRepository::class);
        $suggestions = $repository->findByTenantAndPropertyOrdered($data['tenant'], $data['property']);
        self::assertCount(7, $suggestions);
        $reviewInvoiceSuggestion = current(array_filter($suggestions, static fn (NextBestActionSuggestion $suggestion): bool => NextBestActionSuggestion::TYPE_REVIEW_OVERDUE_INVOICE === $suggestion->getSuggestionType()));
        self::assertInstanceOf(NextBestActionSuggestion::class, $reviewInvoiceSuggestion);

        $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#next-best-actions-card', 'Review Overdue Invoice');
        self::assertSelectorTextContains('#next-best-actions-card', 'Offer Maintenance Plan');
        self::assertSelectorTextContains('#next-best-actions-card', 'Schedule Follow Up');

        $crawler = $this->client->getCrawler();
        $approveToken = $this->findActionToken($crawler, NextBestActionSuggestion::TYPE_REVIEW_OVERDUE_INVOICE, 'approved');
        $this->client->request('POST', sprintf('/crm/properties/%d/next-best-actions/%d/status/approved', $data['property']->getId(), $reviewInvoiceSuggestion->getId()), [
            '_token' => $approveToken,
        ]);
        self::assertResponseRedirects('/crm/properties/'.$data['property']->getId());

        $this->entityManager->clear();
        $updatedSuggestion = $repository->findOneByTenantAndId($data['tenant'], $reviewInvoiceSuggestion->getId());
        self::assertSame(NextBestActionSuggestion::STATUS_APPROVED, $updatedSuggestion?->getStatus());
    }

    private function findActionToken(Crawler $crawler, string $type, string $status): string
    {
        $row = $crawler->filter(sprintf('#next-best-actions-card [data-next-best-action-type="%s"]', $type));
        self::assertGreaterThan(0, $row->count());

        $form = $row->filter(sprintf('form[action*="/status/%s"]', $status));
        self::assertGreaterThan(0, $form->count());

        return (string) $form->filter('input[name="_token"]')->attr('value');
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

    private function selectTenant(Tenant $tenant): void
    {
        $this->client->request('GET', '/crm/no-tenant');
        $session = $this->client->getRequest()->getSession();
        $session->set('crm.current_tenant_id', $tenant->getId());
        $session->save();
    }

    /**
     * @return array{tenant: Tenant, user: User, property: Property}
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
            'user' => $user,
            'property' => $property,
        ];
    }
}
