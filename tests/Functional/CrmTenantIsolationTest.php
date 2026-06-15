<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Entity\CallTranscript;
use App\Entity\Contact;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\Quote;
use App\Entity\Rfq;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CrmTenantIsolationTest extends WebTestCase
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

    public function testUserWithoutMembershipIsRedirectedToNoTenantPage(): void
    {
        $user = (new User())
            ->setEmail('nomembership@example.com')
            ->setPassword('unused')
            ->setRoles(['ROLE_USER']);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/crm');
        self::assertResponseRedirects('/crm/no-tenant');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'No active HVAC company');

        $this->client->request('GET', '/crm/properties');
        self::assertResponseRedirects('/crm/no-tenant');
    }

    public function testTenantScopedRoutesHideOtherTenantRecords(): void
    {
        $data = $this->seedTenantData();
        $this->client->loginUser($data['otherUser']);

        foreach ([
            '/crm/properties/'.$data['property']->getId(),
            '/crm/rfq-invitations/'.$data['rfqInvitation']->getId().'/accept',
            '/crm/estimates/'.$data['estimate']->getId(),
            '/crm/quotes/'.$data['quote']->getId(),
            '/crm/invoices/'.$data['invoice']->getId(),
        ] as $path) {
            $method = str_contains($path, '/accept') ? 'POST' : 'GET';
            $this->client->request($method, $path);
            self::assertResponseStatusCodeSame(404, sprintf('Expected 404 for %s %s', $method, $path));
        }
    }

    public function testTranscriptAndRecordingRoutesAreForbiddenAcrossTenants(): void
    {
        $data = $this->seedTenantData();
        $this->client->loginUser($data['otherUser']);

        $this->client->request('GET', '/transcripts/'.$data['transcript']->getId());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/transcripts/'.$data['transcript']->getId().'/messages');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/recordings/'.$data['recording']->getId().'/download-url');
        self::assertResponseStatusCodeSame(403);
    }

    public function testClickToCallRequiresAllowedMembershipRole(): void
    {
        $data = $this->seedTenantData();

        $accountingUser = (new User())
            ->setEmail('accounting@example.com')
            ->setPassword('unused')
            ->setRoles(['ROLE_USER']);
        $this->entityManager->persist($accountingUser);
        $this->entityManager->persist(
            (new UserTenantMembership($accountingUser, $data['tenant']))
                ->setRoles([UserTenantMembership::ROLE_ACCOUNTING])
                ->setIsDefault(true),
        );
        $this->entityManager->flush();

        $this->client->loginUser($accountingUser);
        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/click-to-call',
            $data['property']->getId(),
            $data['contact']->getId(),
        ));

        self::assertResponseStatusCodeSame(403);
    }

    public function testPendingInvitationMustBeAcceptedBeforeTenantAccessIsEnabled(): void
    {
        $tenant = (new Tenant('Invited Tenant'))->setEmail('invited-tenant@example.com');
        $user = (new User())
            ->setEmail('invitee@example.com')
            ->setPassword('unused')
            ->setRoles(['ROLE_USER'])
            ->setIsActive(false);
        $membership = (new UserTenantMembership($user, $tenant))
            ->setRoles([UserTenantMembership::ROLE_DISPATCH])
            ->markInvited('invite-token-123');

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($membership);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/crm');
        self::assertResponseRedirects('/crm/no-tenant');

        $this->client->request('GET', '/invite/invite-token-123');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Accept Invitation');

        $this->client->submitForm('Accept Invitation', [
            'firstName' => 'Pat',
            'lastName' => 'Lee',
            'displayName' => 'Pat Lee',
            'cellPhone' => '+14165559999',
            'newPassword' => 'password123',
            'confirmNewPassword' => 'password123',
        ]);

        self::assertResponseRedirects('/login');

        $this->entityManager->clear();
        $acceptedUser = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'invitee@example.com']);
        self::assertInstanceOf(User::class, $acceptedUser);

        $this->client->loginUser($acceptedUser);
        $this->client->request('GET', '/crm');
        self::assertResponseRedirects('/crm/properties');
    }

    public function testPropertyContactArchiveRemovesOnlyTheLink(): void
    {
        $data = $this->seedTenantData();
        $secondContact = (new Contact($data['tenant'], 'Second Contact'))->setPrimaryEmail('second@example.com');
        $this->entityManager->persist($secondContact);
        $this->entityManager->persist(
            (new PropertyContact($data['tenant'], $data['property'], $secondContact))
                ->setRelationshipType(PropertyContact::RELATIONSHIP_BILLING_CONTACT)
                ->setIsPrimary(false),
        );
        $this->entityManager->flush();

        $this->client->loginUser($data['ownerUser']);
        $crawler = $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        $csrf = $crawler->filter('form[action="/crm/properties/'.$data['property']->getId().'/contacts/'.$data['contact']->getId().'/link/archive"] input[name="_token"]')->attr('value');

        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/link/archive',
            $data['property']->getId(),
            $data['contact']->getId(),
        ), ['_token' => $csrf]);

        self::assertResponseRedirects('/crm/properties/'.$data['property']->getId());

        $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertSelectorTextContains('body', 'Second Contact');
    }

    public function testPropertyTimelineRendersAndAcceptsNotes(): void
    {
        $data = $this->seedTenantData();

        $this->client->loginUser($data['ownerUser']);
        $crawler = $this->client->request('GET', '/crm/properties/'.$data['property']->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Communication Timeline');
        self::assertSelectorTextContains('body', 'Transcript Messages');
        self::assertSelectorTextContains('body', 'Test transcript');

        $noteToken = $crawler->filter('form[action="/crm/properties/'.$data['property']->getId().'/timeline/notes"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/properties/%d/timeline/notes', $data['property']->getId()), [
            '_token' => $noteToken,
            'noteText' => 'Follow-up required after today\'s call.',
            'disposition' => 'follow_up_required',
        ]);

        self::assertResponseRedirects('/crm/properties/'.$data['property']->getId());

        $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertSelectorTextContains('body', 'Follow-up required after today\'s call.');
        self::assertSelectorTextContains('body', 'Follow-up required');
    }

    public function testCommunicationSearchFindsTranscriptHistory(): void
    {
        $data = $this->seedTenantData();

        $this->client->loginUser($data['ownerUser']);
        $this->client->request('GET', '/crm/communications/search?q=Test transcript');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Communication Search');
        self::assertSelectorTextContains('body', 'Test transcript');
        self::assertSelectorTextContains('body', $data['property']->getDisplayAddress());
    }

    public function testQuoteAndInvoiceEventsAppearInTimeline(): void
    {
        $data = $this->seedTenantData();

        $this->client->loginUser($data['ownerUser']);

        $crawler = $this->client->request('GET', '/crm/quotes/'.$data['quote']->getId());
        $quoteStatusToken = $crawler->filter('form[action="/crm/quotes/'.$data['quote']->getId().'/status"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/quotes/%d/status', $data['quote']->getId()), [
            '_token' => $quoteStatusToken,
            'status' => Quote::STATUS_ACCEPTED,
        ]);

        $crawler = $this->client->request('GET', '/crm/quotes/'.$data['quote']->getId());
        $convertToken = $crawler->filter('form[action="/crm/quotes/'.$data['quote']->getId().'/convert-to-invoice"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/quotes/%d/convert-to-invoice', $data['quote']->getId()), [
            '_token' => $convertToken,
        ]);

        self::assertResponseRedirects();

        $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertSelectorTextContains('body', 'Quote status changed to accepted.');
        self::assertSelectorTextContains('body', 'Invoice created from quote.');
    }

    public function testPublicQuoteAcceptanceAndPdfAreAvailable(): void
    {
        $data = $this->seedTenantData();
        $shareToken = 'public-quote-token-123';
        $data['quote']->setShareToken($shareToken)->setStatus(Quote::STATUS_SENT)->setSentAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->client->request('GET', '/quotes/'.$shareToken);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $data['quote']->getQuoteNumber());
        self::assertSelectorTextContains('body', 'Accept Quote');

        $crawler = $this->client->getCrawler();
        $acceptToken = $crawler->filter('form[action="/quotes/'.$shareToken.'/accept"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/quotes/%s/accept', $shareToken), [
            '_token' => $acceptToken,
            'acceptedByName' => 'Homeowner',
            'acceptedByEmail' => 'owner@example.com',
            'acceptedMessage' => 'Approved.',
        ]);

        self::assertResponseRedirects('/quotes/'.$shareToken);

        $this->entityManager->clear();
        $acceptedQuote = $this->entityManager->getRepository(Quote::class)->find($data['quote']->getId());
        self::assertInstanceOf(Quote::class, $acceptedQuote);
        self::assertSame(Quote::STATUS_ACCEPTED, $acceptedQuote->getStatus());
        self::assertNotNull($acceptedQuote->getAcceptedAt());

        $this->client->request('GET', '/quotes/'.$shareToken.'/pdf');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
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
     * @return array{
     *   tenant: Tenant,
     *   otherTenant: Tenant,
     *   ownerUser: User,
     *   otherUser: User,
     *   property: Property,
     *   contact: Contact,
     *   rfqInvitation: RfqInvitation,
     *   estimate: Estimate,
     *   quote: Quote,
     *   invoice: Invoice,
     *   recording: CallRecording,
     *   transcript: CallTranscript
     * }
     */
    private function seedTenantData(): array
    {
        $tenant = (new Tenant('Tenant One'))->setEmail('tenant1@example.com');
        $otherTenant = (new Tenant('Tenant Two'))->setEmail('tenant2@example.com');
        $this->entityManager->persist($tenant);
        $this->entityManager->persist($otherTenant);

        $ownerUser = (new User())->setEmail('owner@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);
        $otherUser = (new User())->setEmail('other@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);
        $this->entityManager->persist($ownerUser);
        $this->entityManager->persist($otherUser);

        $this->entityManager->persist(
            (new UserTenantMembership($ownerUser, $tenant))
                ->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])
                ->setIsDefault(true),
        );
        $this->entityManager->persist(
            (new UserTenantMembership($otherUser, $otherTenant))
                ->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])
                ->setIsDefault(true),
        );

        $property = (new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1'))->setCountry('CA');
        $contact = (new Contact($tenant, 'Tenant Contact'))->setPrimaryPhone('+14165550123');
        $this->entityManager->persist($property);
        $this->entityManager->persist($contact);
        $this->entityManager->persist(
            (new PropertyContact($tenant, $property, $contact))
                ->setRelationshipType(PropertyContact::RELATIONSHIP_OWNER)
                ->setIsPrimary(true),
        );

        $rfq = (new Rfq('22 Quote Lane', 'Toronto', 'ON', 'M2M2M2'))->setStatus(Rfq::STATUS_SENT_TO_VENDORS);
        $rfqInvitation = new RfqInvitation($tenant, $rfq);
        $this->entityManager->persist($rfq);
        $this->entityManager->persist($rfqInvitation);

        $estimate = (new Estimate($tenant, $property))
            ->setContact($contact)
            ->setRfqInvitation($rfqInvitation)
            ->setStatus(Estimate::STATUS_DRAFT);
        $this->entityManager->persist($estimate);

        $quote = (new Quote($tenant, $property, 'Q-1001'))
            ->setContact($contact)
            ->setEstimate($estimate)
            ->setStatus(Quote::STATUS_DRAFT);
        $this->entityManager->persist($quote);

        $invoice = (new Invoice($tenant, $property, 'I-1001'))
            ->setContact($contact)
            ->setQuote($quote)
            ->setStatus(Invoice::STATUS_DRAFT);
        $this->entityManager->persist($invoice);

        $session = (new CallSession('provider-session-1'))
            ->setTenant($tenant)
            ->setProperty($property)
            ->setContact($contact)
            ->setFlowType(CallSession::FLOW_TYPE_CLICK_TO_CALL)
            ->setStatus('completed');
        $this->entityManager->persist($session);

        $recording = (new CallRecording($session, 'imported'))
            ->setProviderRecordingId('rec-1001')
            ->setS3Bucket('test-bucket')
            ->setS3Key('recordings/rec-1001.mp3');
        $this->entityManager->persist($recording);

        $transcript = (new CallTranscript($recording, 'model-1', 'completed'))
            ->setCallSession($session)
            ->setTranscriptText('Test transcript');
        $this->entityManager->persist($transcript);

        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'otherTenant' => $otherTenant,
            'ownerUser' => $ownerUser,
            'otherUser' => $otherUser,
            'property' => $property,
            'contact' => $contact,
            'rfqInvitation' => $rfqInvitation,
            'estimate' => $estimate,
            'quote' => $quote,
            'invoice' => $invoice,
            'recording' => $recording,
            'transcript' => $transcript,
        ];
    }
}
