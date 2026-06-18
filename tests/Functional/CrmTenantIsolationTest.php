<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Entity\CallSummary;
use App\Entity\CallTranscript;
use App\Entity\CallTranscriptSegment;
use App\Entity\Equipment;
use App\Entity\EquipmentServiceRecord;
use App\Entity\Contact;
use App\Entity\Estimate;
use App\Entity\InvoiceAccountingSyncRecord;
use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\Property;
use App\Entity\PropertyContact;
use App\Entity\Quote;
use App\Entity\Rfq;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\JobRepository;
use App\Repository\UserRepository;
use App\Repository\InvoiceAccountingSyncRecordRepository;
use App\Service\AuditLogger;
use App\Service\InvoiceAccountingBoundaryService;
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
        $this->entityManager->clear();
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

    public function testBridgeCallRequiresAllowedMembershipRole(): void
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
            '/crm/properties/%d/contacts/%d/bridge-call',
            $data['property']->getId(),
            $data['contact']->getId(),
        ));

        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/click-to-call',
            $data['property']->getId(),
            $data['contact']->getId(),
        ));

        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf(
            '/crm/properties/%d/contacts/%d/browser-call',
            $data['property']->getId(),
            $data['contact']->getId(),
        ));

        self::assertResponseStatusCodeSame(403);
    }

    public function testPropertyPageShowsBrowserAndBridgeCallButtons(): void
    {
        $data = $this->seedTenantData();
        $this->client->loginUser($data['ownerUser']);
        $this->client->request('GET', '/crm');
        $this->client->getRequest()->getSession()->set('crm.current_tenant_id', $data['tenant']->getId());
        $this->client->getRequest()->getSession()->save();

        $this->client->request('GET', '/crm/properties/'.$data['property']->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Browser Call');
        self::assertSelectorTextContains('body', 'Bridge Call');
        self::assertSelectorTextContains('body', 'Browser Softphone');
        self::assertSelectorTextContains('body', 'Place Browser Call');
        self::assertSelectorTextContains('body', 'Mute');
        self::assertSelectorTextContains('body', 'Keypad');
        self::assertSelectorTextContains('body', 'Start Recording');
        self::assertSelectorTextContains('body', 'Hang Up');
        self::assertSelectorTextContains('body', 'Recording inactive');
        self::assertSelectorTextContains('body', 'Connection state:');
        self::assertSelectorTextContains('body', 'Disconnected');
        self::assertSelectorTextContains('body', 'Call state:');
        self::assertSelectorTextContains('body', 'Idle');
        self::assertSelectorExists(sprintf('form[action="/crm/properties/%d/contacts/%d/browser-call"]', $data['property']->getId(), $data['contact']->getId()));
        self::assertSelectorExists(sprintf('form[action="/crm/properties/%d/contacts/%d/bridge-call"]', $data['property']->getId(), $data['contact']->getId()));
        self::assertSelectorExists('[data-controller="browser-softphone"]');
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
        self::assertSelectorTextContains('body', 'Call Insights');
        self::assertSelectorTextContains('body', 'Suggested contacts');
        self::assertSelectorTextContains('body', 'Suggested properties');
        self::assertSelectorTextContains('body', 'Tenant Contact');
        self::assertSelectorTextContains('body', 'Quote Requested');
        self::assertSelectorTextContains('body', 'Call Tenant Contact back to confirm the estimate and next service window.');

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
        $this->client->request('GET', '/crm/communications/search?q=furnace filter');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Communication Search');
        self::assertSelectorTextContains('body', 'Transcript match');
        self::assertSelectorTextContains('body', 'Please schedule a callback about the furnace filter.');
        self::assertSelectorTextContains('body', $data['property']->getDisplayAddress());
    }

    public function testEstimateAndPropertySuggestionCardsRender(): void
    {
        $data = $this->seedTenantData();

        $equipment = (new Equipment($data['tenant'], $data['property'], Equipment::TYPE_FURNACE))
            ->setInstalledAt(new \DateTimeImmutable('2005-01-01'))
            ->setStatus(Equipment::STATUS_ACTIVE);
        $this->entityManager->persist($equipment);
        $this->entityManager->persist(
            (new EquipmentServiceRecord($data['tenant'], $data['property']))
                ->setEquipment($equipment)
                ->setCompletedAt(new \DateTimeImmutable('2026-01-01'))
                ->setRecommendedReplacementNotes('Replacement recommended within the next season.')
                ->setServiceType('Furnace inspection'),
        );
        $this->entityManager->flush();

        $this->client->loginUser($data['ownerUser']);

        $this->client->request('GET', '/crm/estimates/'.$data['estimate']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Sales and Service Suggestions');
        self::assertSelectorTextContains('body', 'Suggested Line Items');
        self::assertSelectorTextContains('body', 'Furnace diagnostic and repair');
        self::assertSelectorTextContains('body', 'Follow-up Suggestions');

        $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Service Suggestions');
        self::assertSelectorTextContains('body', 'Equipment Replacement Flags');
        self::assertSelectorTextContains('body', 'Furnace');
        self::assertSelectorTextContains('body', 'High');
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
        $job = static::getContainer()->get(JobRepository::class)->findOneByQuote($acceptedQuote);
        self::assertInstanceOf(\App\Entity\Job::class, $job);
        self::assertSame('Work order for quote '.$acceptedQuote->getQuoteNumber(), $job->getTitle());

        $this->client->request('GET', '/quotes/'.$shareToken.'/pdf');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));

        $this->client->loginUser($data['ownerUser']);
        $this->client->request('GET', '/crm/properties/'.$data['property']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Job created from accepted quote.');
    }

    public function testInvoiceEditorAndPaymentsUpdateBalanceAndStatus(): void
    {
        $data = $this->seedTenantData();

        $this->client->loginUser($data['ownerUser']);
        $crawler = $this->client->request('GET', '/crm/invoices/'.$data['invoice']->getId());
        self::assertResponseIsSuccessful();

        $lineItemToken = $crawler->filter('form[action="/crm/invoices/'.$data['invoice']->getId().'/line-items"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/invoices/%d/line-items', $data['invoice']->getId()), [
            '_token' => $lineItemToken,
            'sectionLabel' => 'Repair scope',
            'description' => 'Replace contactor',
            'quantity' => '1',
            'unitPrice' => '100.00',
        ]);

        self::assertResponseRedirects('/crm/invoices/'.$data['invoice']->getId());

        $crawler = $this->client->request('GET', '/crm/invoices/'.$data['invoice']->getId());
        self::assertSelectorTextContains('body', 'Replace contactor');
        self::assertSelectorTextContains('body', '$100.00');

        $paymentToken = $crawler->filter('form[action="/crm/invoices/'.$data['invoice']->getId().'/payments"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/invoices/%d/payments', $data['invoice']->getId()), [
            '_token' => $paymentToken,
            'amount' => '25.00',
            'kind' => 'received',
            'method' => 'Cash',
            'receivedAt' => '2026-06-15',
            'reference' => 'REC-1',
            'memo' => 'Partial payment.',
        ]);

        self::assertResponseRedirects('/crm/invoices/'.$data['invoice']->getId());

        $this->entityManager->clear();
        $invoice = $this->entityManager->getRepository(Invoice::class)->find($data['invoice']->getId());
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->getStatus());
        self::assertSame(2500, $invoice->getAmountPaidCents());

        $this->client->request('GET', '/crm/invoices/'.$data['invoice']->getId());
        self::assertSelectorTextContains('body', 'Balance $75.00');
        self::assertSelectorTextContains('body', 'REC-1');
    }

    public function testInvoiceOutputSendReminderAndAgingAreAvailable(): void
    {
        $data = $this->seedTenantData();
        $data['invoice']
            ->setDueAt(new \DateTimeImmutable('yesterday'))
            ->setIssuedAt(new \DateTimeImmutable('yesterday'))
            ->setStatus(Invoice::STATUS_UNPAID);
        $this->entityManager->persist(
            (new InvoiceLineItem($data['tenant'], $data['invoice'], 'Service work'))
                ->setQuantity('1.00')
                ->setUnitPriceCents(10000)
                ->setTotalCents(10000)
                ->setSortOrder(1),
        );
        $this->entityManager->flush();

        $this->client->loginUser($data['ownerUser']);

        $this->client->request('GET', '/crm/invoices/'.$data['invoice']->getId().'/print');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $data['invoice']->getInvoiceNumber());

        $this->client->request('GET', '/crm/invoices/'.$data['invoice']->getId().'/pdf');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));

        $crawler = $this->client->request('GET', '/crm/invoices/'.$data['invoice']->getId());
        $sendToken = $crawler->filter('form[action="/crm/invoices/'.$data['invoice']->getId().'/send"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/invoices/%d/send', $data['invoice']->getId()), [
            '_token' => $sendToken,
        ]);

        self::assertResponseRedirects('/crm/invoices/'.$data['invoice']->getId());

        $crawler = $this->client->request('GET', '/crm/invoices/'.$data['invoice']->getId());
        $remindToken = $crawler->filter('form[action="/crm/invoices/'.$data['invoice']->getId().'/remind"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/crm/invoices/%d/remind', $data['invoice']->getId()), [
            '_token' => $remindToken,
        ]);

        self::assertResponseRedirects('/crm/invoices/'.$data['invoice']->getId());

        $this->entityManager->clear();
        $invoice = $this->entityManager->getRepository(Invoice::class)->find($data['invoice']->getId());
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertNotNull($invoice->getSentAt());
        self::assertSame(1, $invoice->getReminderCount());
        self::assertNotNull($invoice->getLastReminderAt());

        $this->client->request('GET', '/crm/invoices/aging');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $invoice->getInvoiceNumber());
    }

    public function testInvoiceShowDisplaysAccountingBoundaryRecords(): void
    {
        $data = $this->seedTenantData();
        $this->entityManager->persist(
            (new InvoiceAccountingSyncRecord($data['invoice'], InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE))
                ->markPending()
                ->setExternalId('qb-1001')
                ->setExternalNumber('QB-INV-9001')
                ->setErrorMessage('Queued for export.'),
        );
        $this->entityManager->flush();

        $this->client->loginUser($data['ownerUser']);
        $this->client->request('GET', '/crm/invoices/'.$data['invoice']->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Accounting Integrations');
        self::assertSelectorTextContains('body', 'QuickBooks Online');
        self::assertSelectorTextContains('body', 'Pending export');
        self::assertSelectorTextContains('body', 'qb-1001');
        self::assertSelectorTextContains('body', 'QB-INV-9001');
        self::assertSelectorTextContains('body', 'Queued for export.');
    }

    public function testInvoiceShowDisplaysRetryScheduledAccountingBoundaryRecords(): void
    {
        $data = $this->seedTenantData();
        $this->entityManager->persist(
            (new InvoiceAccountingSyncRecord($data['invoice'], InvoiceAccountingSyncRecord::PROVIDER_XERO))
                ->markRetryScheduled(new \DateTimeImmutable('+45 minutes'), 'Xero temporarily unavailable.', ['attempt' => 2]),
        );
        $this->entityManager->flush();

        $this->client->loginUser($data['ownerUser']);
        $this->client->request('GET', '/crm/invoices/'.$data['invoice']->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Retry scheduled');
        self::assertSelectorTextContains('body', 'Retries: 1');
        self::assertSelectorTextContains('body', 'Next retry');
        self::assertSelectorTextContains('body', 'Xero temporarily unavailable.');
    }

    public function testRetryDueRepositoryFindsOnlyDueRecords(): void
    {
        $data = $this->seedTenantData();
        $dueRecord = (new InvoiceAccountingSyncRecord($data['invoice'], InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE))
            ->markRetryScheduled(new \DateTimeImmutable('-5 minutes'), 'Temporary failure.');
        $futureRecord = (new InvoiceAccountingSyncRecord($data['invoice'], InvoiceAccountingSyncRecord::PROVIDER_XERO))
            ->markRetryScheduled(new \DateTimeImmutable('+5 minutes'), 'Temporary failure.');
        $this->entityManager->persist($dueRecord);
        $this->entityManager->persist($futureRecord);
        $this->entityManager->flush();

        /** @var InvoiceAccountingSyncRecordRepository $repository */
        $repository = static::getContainer()->get(InvoiceAccountingSyncRecordRepository::class);
        $records = $repository->findRetryDueByTenant($data['tenant'], new \DateTimeImmutable());

        self::assertCount(1, $records);
        self::assertSame($dueRecord->getId(), $records[0]->getId());
        self::assertSame(InvoiceAccountingSyncRecord::STATUS_RETRY_SCHEDULED, $records[0]->getStatus());
    }

    public function testInvoiceShowDisplaysAccountingExportLogTrail(): void
    {
        $data = $this->seedTenantData();
        $service = new InvoiceAccountingBoundaryService(
            static::getContainer()->get(InvoiceAccountingSyncRecordRepository::class),
            $this->entityManager,
            static::getContainer()->get(AuditLogger::class),
        );

        $service->beginExport($data['invoice'], InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE);
        $service->markFailed(
            $data['invoice'],
            InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE,
            'QuickBooks API timeout.',
            ['httpStatus' => 504],
        );
        $service->scheduleRetry(
            $data['invoice'],
            InvoiceAccountingSyncRecord::PROVIDER_QUICKBOOKS_ONLINE,
            new \DateTimeImmutable('+1 hour'),
            'Retrying after timeout.',
            ['httpStatus' => 504],
        );

        $this->client->loginUser($data['ownerUser']);
        $this->client->request('GET', '/crm/invoices/'.$data['invoice']->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Export Log');
        self::assertSelectorTextContains('body', 'invoice.accounting_export_started');
        self::assertSelectorTextContains('body', 'invoice.accounting_export_failed');
        self::assertSelectorTextContains('body', 'invoice.accounting_retry_scheduled');
        self::assertSelectorTextContains('body', 'QuickBooks API timeout.');
    }

    public function testInvoiceShowDisplaysAccountingExportPayloadPreview(): void
    {
        $data = $this->seedTenantData();
        $this->entityManager->persist(
            (new InvoiceLineItem($data['tenant'], $data['invoice'], 'Service work'))
                ->setQuantity('1.00')
                ->setUnitPriceCents(10000)
                ->setTotalCents(10000)
                ->setSortOrder(1),
        );
        $this->entityManager->flush();

        $this->client->loginUser($data['ownerUser']);
        $this->client->request('GET', '/crm/invoices/'.$data['invoice']->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Export Payloads');
        self::assertSelectorTextContains('body', 'QuickBooks Online');
        self::assertSelectorTextContains('body', 'Xero');
        self::assertSelectorTextContains('body', '"provider": "quickbooks_online"');
        self::assertSelectorTextContains('body', '"type": "ACCREC"');
    }

    public function testReportingDashboardShowsRevenueAndThroughputMetrics(): void
    {
        $data = $this->seedTenantData();
        $this->entityManager->flush();
        $this->entityManager->clear();

        $ownerUser = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $data['ownerUser']->getEmail()]);
        self::assertInstanceOf(User::class, $ownerUser);
        $this->client->loginUser($ownerUser);
        $this->client->request('GET', '/crm/reporting');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Reporting Dashboard');
        self::assertSelectorTextContains('body', 'Tenant One · Last 90 days');
        self::assertSelectorTextContains('body', 'Estimates');
        self::assertSelectorTextContains('body', 'Quotes Sent');
        self::assertSelectorTextContains('body', 'Pipeline');
        self::assertSelectorTextContains('body', 'Calls');
        self::assertSelectorTextContains('body', 'Jobs Completed');
        self::assertSelectorTextContains('body', 'Quote Acceptance');
        self::assertSelectorTextContains('body', 'Revenue Pipeline');
        self::assertSelectorTextContains('body', 'Call Volume');
        self::assertSelectorTextContains('body', 'Service Throughput by Technician');
        self::assertSelectorTextContains('body', 'Service Throughput by Dispatcher');
    }

    public function testTenantAdminCanEditInvoiceSettingsFromProfile(): void
    {
        $data = $this->seedTenantData();

        $this->client->loginUser($data['ownerUser']);
        $crawler = $this->client->request('GET', '/crm/profile');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Save Profile', [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'invoiceDueDays' => '21',
            'invoicePaymentInstructions' => "Send payment by e-transfer.",
            'invoiceFooter' => 'Thank you for choosing us.',
        ]);

        self::assertResponseRedirects('/crm/profile');

        $this->entityManager->clear();
        $tenant = $this->entityManager->getRepository(Tenant::class)->find($data['tenant']->getId());
        self::assertInstanceOf(Tenant::class, $tenant);
        self::assertSame(21, $tenant->getInvoiceDueDays());
        self::assertSame('Send payment by e-transfer.', $tenant->getInvoicePaymentInstructions());
        self::assertSame('Thank you for choosing us.', $tenant->getInvoiceFooter());
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
     *   transcript: CallTranscript,
     *   summary: CallSummary
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
        $contact = (new Contact($tenant, 'Tenant Contact'))->setPrimaryPhone('+14165550123')->setPrimaryEmail('billing@example.com');
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
            ->setInboundTo('+14165550123')
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
        $segment = (new CallTranscriptSegment($transcript, 1, 'Please schedule a callback about the furnace filter.'))
            ->setSpeakerRole('customer')
            ->setOccurredAt(new \DateTimeImmutable('2026-06-16 12:00:00'));
        $this->entityManager->persist($segment);

        $summary = (new CallSummary($transcript))
            ->setStatus('available')
            ->setSummaryText('Tenant Contact asked for a repair estimate and wants a callback tomorrow.')
            ->setSummaryJson([
                'summary' => 'Tenant Contact asked for a repair estimate and wants a callback tomorrow.',
                'customer_intent' => 'Request a repair estimate and follow-up callback.',
                'participants' => ['Tenant Contact'],
                'equipment_mentions' => ['Furnace'],
                'appointment_mentions' => ['callback tomorrow'],
                'quote_or_price_mentions' => ['estimate'],
                'action_items' => ['Call Tenant Contact back to confirm the estimate and next service window.'],
                'urgency' => 'high',
                'sentiment' => 'neutral',
                'recommended_disposition' => 'quote_requested',
                'next_step' => 'Call back to confirm the estimate and next service window.',
            ]);
        $this->entityManager->persist($summary);

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
            'summary' => $summary,
        ];
    }
}
