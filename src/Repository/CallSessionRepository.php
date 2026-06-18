<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CallSession;
use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CallSession> */
class CallSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CallSession::class);
    }

    public function findOneByProviderSessionId(string $providerSessionId): ?CallSession
    {
        return $this->findOneBy(['providerSessionId' => $providerSessionId]);
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?CallSession
    {
        return $this->createQueryBuilder('session')
            ->andWhere('session.tenant = :tenant')
            ->andWhere('session.id = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestUpdatedAtByProperty(Property $property): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('session')
            ->select('MAX(session.updatedAt) AS updatedAt')
            ->andWhere('session.property = :property')
            ->setParameter('property', $property)
            ->getQuery()
            ->getSingleScalarResult();

        if ($result instanceof \DateTimeImmutable) {
            return $result;
        }

        return '' !== (string) $result ? new \DateTimeImmutable((string) $result) : null;
    }

    /**
     * @return list<CallSession>
     */
    public function findRecentByProperty(Property $property, int $limit = 10): array
    {
        return $this->createQueryBuilder('session')
            ->andWhere('session.property = :property')
            ->setParameter('property', $property)
            ->orderBy('session.createdAt', 'DESC')
            ->addOrderBy('session.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLatestBrowserCallIntent(Tenant $tenant, Property $property, Contact $contact, User $user): ?CallSession
    {
        return $this->createQueryBuilder('session')
            ->andWhere('session.tenant = :tenant')
            ->andWhere('session.property = :property')
            ->andWhere('session.contact = :contact')
            ->andWhere('session.csrUser = :user')
            ->andWhere('session.callMode = :callMode')
            ->andWhere('session.status IN (:statuses)')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->setParameter('contact', $contact)
            ->setParameter('user', $user)
            ->setParameter('callMode', CallSession::CALL_MODE_BROWSER)
            ->setParameter('statuses', [CallSession::CALL_STATE_INITIATED])
            ->orderBy('session.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<CallSession> */
    public function findByTenantAndProperty(Tenant $tenant, Property $property): array
    {
        return $this->createQueryBuilder('session')
            ->andWhere('session.tenant = :tenant')
            ->andWhere('session.property = :property')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->orderBy('session.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<CallSession> */
    public function findRecent(int $limit = 20): array
    {
        return $this->createQueryBuilder('session')
            ->andWhere('session.parentCallSession IS NULL')
            ->orderBy('session.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countBetween(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('session')
            ->select('COUNT(session.id)')
            ->andWhere('session.tenant = :tenant')
            ->andWhere('session.createdAt >= :from')
            ->andWhere('session.createdAt < :to')
            ->setParameter('tenant', $tenant)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<array{propertyId:int|null,propertyLabel:string,callCount:int}>
     */
    public function countByPropertyBetween(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 5): array
    {
        return array_map(static fn (array $row): array => [
            'propertyId' => isset($row['propertyId']) ? (int) $row['propertyId'] : null,
            'propertyLabel' => (string) $row['propertyLabel'],
            'callCount' => (int) $row['callCount'],
        ], $this->createQueryBuilder('session')
            ->select('IDENTITY(session.property) AS propertyId, COALESCE(CONCAT(property.addressLine1, \', \', property.city, \', \', property.province, \' \', property.postalCode), \'Unlinked property\') AS propertyLabel, COUNT(session.id) AS callCount')
            ->leftJoin('session.property', 'property')
            ->andWhere('session.tenant = :tenant')
            ->andWhere('session.createdAt >= :from')
            ->andWhere('session.createdAt < :to')
            ->setParameter('tenant', $tenant)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('session.property, property.id, property.addressLine1, property.city, property.province, property.postalCode')
            ->orderBy('callCount', 'DESC')
            ->addOrderBy('propertyLabel', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * @return list<array{contactId:int|null,contactLabel:string,callCount:int}>
     */
    public function countByContactBetween(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 5): array
    {
        return array_map(static fn (array $row): array => [
            'contactId' => isset($row['contactId']) ? (int) $row['contactId'] : null,
            'contactLabel' => (string) $row['contactLabel'],
            'callCount' => (int) $row['callCount'],
        ], $this->createQueryBuilder('session')
            ->select('IDENTITY(session.contact) AS contactId, COALESCE(contact.displayName, \'Unlinked contact\') AS contactLabel, COUNT(session.id) AS callCount')
            ->leftJoin('session.contact', 'contact')
            ->andWhere('session.tenant = :tenant')
            ->andWhere('session.createdAt >= :from')
            ->andWhere('session.createdAt < :to')
            ->setParameter('tenant', $tenant)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('session.contact, contact.id, contact.displayName')
            ->orderBy('callCount', 'DESC')
            ->addOrderBy('contactLabel', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * @param list<CallSession> $sessions
     *
     * @return array<int, int|null>
     */
    public function findBilledDurationSeconds(array $sessions): array
    {
        $sessionIds = array_values(array_filter(array_map(
            static fn (CallSession $session): ?int => $session->getId(),
            $sessions,
        )));
        if ([] === $sessionIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT COALESCE(session.parent_call_session_id, session.id) AS root_session_id,
                       SUM(leg.billed_duration_seconds) AS billed_seconds
                FROM call_leg leg
                INNER JOIN call_session session ON session.id = leg.call_session_id
                WHERE COALESCE(session.parent_call_session_id, session.id) IN (?)
                  AND leg.billed_duration_seconds IS NOT NULL
                GROUP BY root_session_id
                SQL,
            [$sessionIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $totals = array_fill_keys($sessionIds, null);
        foreach ($rows as $row) {
            $totals[(int) $row['root_session_id']] = (int) $row['billed_seconds'];
        }

        return $totals;
    }
}
