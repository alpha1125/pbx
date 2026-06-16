<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\Rfq;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RfqOperatorDashboardTest extends WebTestCase
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

    public function testDashboardFiltersPaginateAndCompareAcrossTenants(): void
    {
        $data = $this->seedData();
        $this->client->loginUser($data['operatorUser']);

        $this->client->request('GET', '/crm/rfq-operations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'RFQ Operations');
        self::assertSelectorTextContains('body', 'TP-RFQ-4012');
        self::assertSelectorTextNotContains('body', 'TP-RFQ-4001');
        self::assertSelectorExists('nav[aria-label="RFQ pagination"]');
        self::assertSelectorExists('nav[aria-label="Invitation pagination"]');

        $this->client->request('GET', '/crm/rfq-operations?rfqPage=2&invitationPage=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'TP-RFQ-4001');

        $this->client->request('GET', '/crm/rfq-operations?rfqStatus=submitted');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Submitted');

        $this->client->request('GET', '/crm/rfq-operations?invitationStatus=sent');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Sent');

        $this->client->request('GET', '/crm/rfq-operations/'.$data['compareRfq']->getId().'/compare');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Comparison View');
        self::assertSelectorTextContains('body', 'Vendor A');
        self::assertSelectorTextContains('body', 'Vendor B');
        self::assertSelectorTextContains('body', 'Vendor C');
        self::assertSelectorTextContains('body', 'Viewed');
        self::assertSelectorTextContains('body', 'Declined');
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
     *   operatorUser: User,
     *   compareRfq: Rfq
     * }
     */
    private function seedData(): array
    {
        $operatorTenant = (new Tenant('Operator Tenant'))->setEmail('operator@example.com');
        $operatorUser = (new User())->setEmail('operator@example.com')->setPassword('unused')->setRoles(['ROLE_USER']);
        $this->entityManager->persist($operatorTenant);
        $this->entityManager->persist($operatorUser);
        $this->entityManager->persist(
            (new UserTenantMembership($operatorUser, $operatorTenant))
                ->setRoles([UserTenantMembership::ROLE_TENANT_ADMIN])
                ->setIsDefault(true),
        );

        $vendorA = (new Tenant('Vendor A'))->setRfqVendorEnabled(true);
        $vendorB = (new Tenant('Vendor B'))->setRfqVendorEnabled(true);
        $vendorC = (new Tenant('Vendor C'))->setRfqVendorEnabled(true);
        $this->entityManager->persist($vendorA);
        $this->entityManager->persist($vendorB);
        $this->entityManager->persist($vendorC);

        $baseAt = new \DateTimeImmutable('2026-06-20 10:00:00');
        $compareRfq = (new Rfq('Compare Intake Street', 'Toronto', 'ON', 'M5V 2000'))
            ->setExternalReference('TP-RFQ-COMPARE')
            ->setStatus(Rfq::STATUS_SENT_TO_VENDORS)
            ->touch($baseAt);
        $this->entityManager->persist($compareRfq);

        $compareInvitations = [
            [$vendorA, RfqInvitation::STATUS_SENT, null, null, null],
            [$vendorB, RfqInvitation::STATUS_VIEWED, $baseAt->modify('+10 minutes'), null, null],
            [$vendorC, RfqInvitation::STATUS_DECLINED, null, $baseAt->modify('+15 minutes'), null],
        ];

        foreach ($compareInvitations as [$tenant, $status, $viewedAt, $declinedAt, $reminderAt]) {
            $invitation = new RfqInvitation($tenant, $compareRfq);
            $invitation
                ->setStatus($status)
                ->setInvitedAt($baseAt)
                ->touch($baseAt);
            if ($viewedAt instanceof \DateTimeImmutable) {
                $invitation->setViewedAt($viewedAt);
            }
            if ($declinedAt instanceof \DateTimeImmutable) {
                $invitation->setDeclinedAt($declinedAt);
            }
            if ($reminderAt instanceof \DateTimeImmutable) {
                $invitation->setReminderAt($reminderAt);
            }
            $this->entityManager->persist($invitation);
        }

        for ($index = 1; $index <= 12; ++$index) {
            $rfq = (new Rfq(sprintf('TP-%02d Intake Street', $index), 'Toronto', 'ON', sprintf('M5V %04d', 1000 + $index)))
                ->setExternalReference(sprintf('TP-RFQ-40%02d', $index));
            if ($index % 2 === 0) {
                $rfq->setStatus(Rfq::STATUS_SUBMITTED);
            } else {
                $rfq->setStatus(Rfq::STATUS_QUOTED);
            }

            $rfq->touch($baseAt->modify('+'.$index.' minutes'));
            $this->entityManager->persist($rfq);

            $tenant = (new Tenant('Vendor '.$index))->setRfqVendorEnabled(true);
            $this->entityManager->persist($tenant);
            $invitation = new RfqInvitation($tenant, $rfq);
            $invitation->setInvitedAt($baseAt->modify('+'.$index.' minutes'));
            $invitation->touch($baseAt->modify('+'.$index.' minutes'));

            $invitation->setStatus(RfqInvitation::STATUS_SENT);

            $this->entityManager->persist($invitation);
        }

        $this->entityManager->flush();

        return [
            'operatorUser' => $operatorUser,
            'compareRfq' => $compareRfq,
        ];
    }
}
