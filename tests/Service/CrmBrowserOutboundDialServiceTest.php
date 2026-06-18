<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PropertyContact;
use App\Entity\BrowserSoftphoneSession;
use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Service\AuditLogger;
use App\Service\BrowserSoftphoneSessionService;
use App\Service\CommunicationTimelineProjector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CrmBrowserOutboundDialServiceTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->truncateDatabase();
        $this->entityManager->clear();
    }

    public function testFindByProviderSessionIdReturnsTenantScopedBrowserSession(): void
    {
        $data = $this->seedTenantData();
        $callSession = (new CallSession('provider-dial-lookup'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        $browserSession = new BrowserSoftphoneSession($callSession, $data['tenant'], $data['user'], 'dial-lookup-token');
        $this->entityManager->persist($browserSession);
        $this->entityManager->flush();

        $service = $this->service();
        $resolved = $service->findByProviderSessionId($data['tenant'], $data['user'], $callSession->getProviderSessionId());

        self::assertSame($browserSession->getId(), $resolved->getId());
    }

    public function testFindByProviderSessionIdRejectsCrossTenantOrUserAccess(): void
    {
        $data = $this->seedTenantData();
        $callSession = (new CallSession('provider-dial-lookup-denied'))
            ->setProvider('telnyx')
            ->setTenant($data['tenant'])
            ->setProperty($data['property'])
            ->setContact($data['contact'])
            ->setCsrUser($data['user'])
            ->setCallMode(CallSession::CALL_MODE_BROWSER)
            ->setCallState(CallSession::CALL_STATE_INITIATED)
            ->setStatus('active')
            ->touch();
        $this->entityManager->persist($callSession);

        $browserSession = new BrowserSoftphoneSession($callSession, $data['tenant'], $data['user'], 'dial-lookup-token-denied');
        $this->entityManager->persist($browserSession);

        $otherTenant = (new Tenant('Other Tenant'))->setEmail('other@example.com');
        $otherUser = (new User())
            ->setEmail('other@example.com')
            ->setPassword('unused')
            ->setRoles(['ROLE_USER']);
        $this->entityManager->persist($otherTenant);
        $this->entityManager->persist($otherUser);
        $this->entityManager->persist((new UserTenantMembership($otherUser, $otherTenant))->setRoles([UserTenantMembership::ROLE_SALES])->setIsDefault(true));
        $this->entityManager->flush();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Browser softphone session not found for the current tenant and user.');
        $this->service()->findByProviderSessionId($otherTenant, $otherUser, $callSession->getProviderSessionId());
    }

    private function service(): BrowserSoftphoneSessionService
    {
        $auditLogger = $this->createStub(AuditLogger::class);
        $timelineProjector = $this->createStub(CommunicationTimelineProjector::class);

        return new BrowserSoftphoneSessionService(
            static::getContainer()->get(\App\Repository\BrowserSoftphoneSessionRepository::class),
            $this->entityManager,
            $auditLogger,
            $timelineProjector,
        );
    }

    /**
     * @return array{tenant:Tenant,user:User,property:Property,contact:Contact}
     */
    private function seedTenantData(): array
    {
        $tenant = (new Tenant('Tenant One'))->setEmail('tenant-one@example.com');
        $user = (new User())
            ->setEmail('csr@example.com')
            ->setPassword('unused')
            ->setRoles(['ROLE_USER']);
        $property = new Property($tenant, '10 Heat Street', 'Toronto', 'ON', 'M1M1M1');
        $contact = (new Contact($tenant, 'Tenant Contact'))
            ->setPrimaryPhone('+14165550123');

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($user);
        $this->entityManager->persist($property);
        $this->entityManager->persist($contact);
        $this->entityManager->persist((new UserTenantMembership($user, $tenant))->setRoles([UserTenantMembership::ROLE_SALES])->setIsDefault(true));
        $this->entityManager->persist((new PropertyContact($tenant, $property, $contact))->setIsPrimary(true));
        $this->entityManager->flush();

        return [
            'tenant' => $tenant,
            'user' => $user,
            'property' => $property,
            'contact' => $contact,
        ];
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
}
