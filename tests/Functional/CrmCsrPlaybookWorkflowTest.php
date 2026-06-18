<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Contact;
use App\Entity\CsrPlaybookAttachment;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\CsrPlaybookAttachmentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class CrmCsrPlaybookWorkflowTest extends WebTestCase
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

    public function testPlaybooksAreVisibleAndAttachableFromPropertyAndContactContext(): void
    {
        $data = $this->seedData();
        $this->client->loginUser($data['user']);

        $crawler = $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'CSR Playbooks');
        self::assertSelectorTextContains('body', 'Maintenance Offer');

        $propertyToken = $this->findPlaybookAttachToken(
            $crawler,
            CsrPlaybookAttachment::TYPE_MAINTENANCE_OFFER,
            $data['property']->getId(),
            null,
            null,
        );
        $this->client->request('POST', '/crm/playbooks/attach', [
            '_token' => $propertyToken,
            '_returnTo' => '/crm/properties/'.$data['property']->getId(),
            'playbookType' => CsrPlaybookAttachment::TYPE_MAINTENANCE_OFFER,
            'propertyId' => $data['property']->getId(),
        ]);
        self::assertResponseRedirects('/crm/properties/'.$data['property']->getId());

        $crawler = $this->client->request('GET', '/crm/properties/'.$data['property']->getId().'/contacts/'.$data['contact']->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'CSR Playbooks');
        $contactToken = $this->findPlaybookAttachToken(
            $crawler,
            CsrPlaybookAttachment::TYPE_DORMANT_CUSTOMER_OUTREACH,
            null,
            $data['contact']->getId(),
            null,
        );
        $this->client->request('POST', '/crm/playbooks/attach', [
            '_token' => $contactToken,
            '_returnTo' => '/crm/properties/'.$data['property']->getId(),
            'playbookType' => CsrPlaybookAttachment::TYPE_DORMANT_CUSTOMER_OUTREACH,
            'contactId' => $data['contact']->getId(),
        ]);
        self::assertResponseRedirects('/crm/properties/'.$data['property']->getId());

        $crawler = $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        $opportunityToken = $this->findPlaybookAttachToken(
            $crawler,
            CsrPlaybookAttachment::TYPE_OVERDUE_INVOICE_DISCUSSION,
            $data['property']->getId(),
            null,
            $data['opportunity']->getId(),
        );
        $this->client->request('POST', '/crm/playbooks/attach', [
            '_token' => $opportunityToken,
            '_returnTo' => '/crm/properties/'.$data['property']->getId(),
            'playbookType' => CsrPlaybookAttachment::TYPE_OVERDUE_INVOICE_DISCUSSION,
            'propertyId' => $data['property']->getId(),
            'retentionOpportunityId' => $data['opportunity']->getId(),
        ]);
        self::assertResponseRedirects('/crm/properties/'.$data['property']->getId());

        $this->entityManager->clear();
        $repository = static::getContainer()->get(CsrPlaybookAttachmentRepository::class);
        $attachments = $repository->findByTenantOrdered($data['tenant']);
        self::assertCount(3, $attachments);
        self::assertSame(CsrPlaybookAttachment::TYPE_MAINTENANCE_OFFER, $attachments[2]->getPlaybookType());
        self::assertSame(CsrPlaybookAttachment::TYPE_DORMANT_CUSTOMER_OUTREACH, $attachments[1]->getPlaybookType());
        self::assertSame(CsrPlaybookAttachment::TYPE_OVERDUE_INVOICE_DISCUSSION, $attachments[0]->getPlaybookType());
    }

    private function findPlaybookAttachToken(
        Crawler $crawler,
        string $playbookType,
        ?int $propertyId,
        ?int $contactId,
        ?int $retentionOpportunityId,
    ): string {
        foreach ($crawler->filter('form[action="/crm/playbooks/attach"]') as $formNode) {
            $formCrawler = new Crawler($formNode);
            if ($playbookType !== (string) $formCrawler->filter('input[name="playbookType"]')->attr('value')) {
                continue;
            }

            if (null !== $propertyId && (string) $propertyId !== (string) $formCrawler->filter('input[name="propertyId"]')->attr('value')) {
                continue;
            }

            if (null !== $contactId) {
                $contactField = $formCrawler->filter('input[name="contactId"]');
                if (0 === $contactField->count() || (string) $contactId !== (string) $contactField->attr('value')) {
                    continue;
                }
            }

            if (null !== $retentionOpportunityId) {
                $opportunityField = $formCrawler->filter('input[name="retentionOpportunityId"]');
                if (0 === $opportunityField->count() || (string) $retentionOpportunityId !== (string) $opportunityField->attr('value')) {
                    continue;
                }
            }

            return (string) $formCrawler->filter('input[name="_token"]')->attr('value');
        }

        self::fail(sprintf('Unable to find CSR playbook attach token for %s.', $playbookType));
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
     * @return array{tenant: Tenant, user: User, property: Property, contact: Contact, opportunity: RetentionOpportunity}
     */
    private function seedData(): array
    {
        $tenant = (new Tenant('Tenant One'))->setEmail('tenant@example.com');
        $user = (new User())->setEmail('admin@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);
        $property = new Property($tenant, '100 Main St', 'Toronto', 'ON', 'M1M1M1');
        $contact = (new Contact($tenant, 'Primary Contact'))->setPrimaryPhone('+14165550123');
        $opportunity = new RetentionOpportunity(
            $tenant,
            $property,
            RetentionOpportunity::TYPE_OPEN_INVOICE,
            'property:seed:open_invoice',
            'Outstanding invoice.',
            $contact,
        );

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist((new UserTenantMembership($user, $tenant))->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])->setIsDefault(true));
        $this->entityManager->persist($property);
        $this->entityManager->persist($contact);
        $this->entityManager->persist((new PropertyContact($tenant, $property, $contact))->setIsPrimary(true));
        $this->entityManager->persist($opportunity);
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'user' => $user,
            'property' => $property,
            'contact' => $contact,
            'opportunity' => $opportunity,
        ];
    }
}
