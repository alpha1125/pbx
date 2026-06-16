<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Rfq;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RfqInvitation> */
class RfqInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RfqInvitation::class);
    }

    /** @return list<RfqInvitation> */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('invitation')
            ->leftJoin('invitation.rfq', 'rfq')->addSelect('rfq')
            ->andWhere('invitation.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('invitation.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?RfqInvitation
    {
        return $this->createQueryBuilder('invitation')
            ->leftJoin('invitation.rfq', 'rfq')->addSelect('rfq')
            ->andWhere('invitation.tenant = :tenant')
            ->andWhere('invitation.id = :id')
            ->setParameter('tenant', $tenant)
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByTenantAndRfq(Tenant $tenant, Rfq $rfq): ?RfqInvitation
    {
        return $this->findOneBy([
            'tenant' => $tenant,
            'rfq' => $rfq,
        ]);
    }

    /** @return list<RfqInvitation> */
    public function findDueForExpiry(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('invitation')
            ->andWhere('invitation.status IN (:statuses)')
            ->andWhere('invitation.expiresAt IS NOT NULL')
            ->andWhere('invitation.expiresAt <= :now')
            ->setParameter('statuses', [RfqInvitation::STATUS_SENT, RfqInvitation::STATUS_VIEWED])
            ->setParameter('now', $now)
            ->orderBy('invitation.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<RfqInvitation> */
    public function findDueForReminder(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('invitation')
            ->andWhere('invitation.status IN (:statuses)')
            ->andWhere('invitation.reminderAt IS NOT NULL')
            ->andWhere('invitation.reminderAt <= :now')
            ->setParameter('statuses', [RfqInvitation::STATUS_SENT, RfqInvitation::STATUS_VIEWED])
            ->setParameter('now', $now)
            ->orderBy('invitation.reminderAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<RfqInvitation>
     */
    public function findForOperatorDashboard(?string $status, ?string $search, int $page, int $pageSize): array
    {
        $qb = $this->createQueryBuilder('invitation')
            ->leftJoin('invitation.rfq', 'rfq')->addSelect('rfq')
            ->leftJoin('invitation.tenant', 'tenant')->addSelect('tenant')
            ->orderBy('invitation.updatedAt', 'DESC')
            ->addOrderBy('invitation.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $pageSize))
            ->setMaxResults($pageSize);

        if (null !== $status && '' !== trim($status)) {
            $qb
                ->andWhere('invitation.status = :status')
                ->setParameter('status', trim($status));
        }

        if (null !== $search && '' !== trim($search)) {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $qb
                ->andWhere(
                    $qb->expr()->orX(
                        'LOWER(tenant.name) LIKE :term',
                        'LOWER(rfq.externalReference) LIKE :term',
                        'LOWER(rfq.addressLine1) LIKE :term',
                        'LOWER(COALESCE(rfq.customerName, \'\')) LIKE :term',
                    ),
                )
                ->setParameter('term', $term);
        }

        return $qb->getQuery()->getResult();
    }

    public function countForOperatorDashboard(?string $status, ?string $search): int
    {
        $qb = $this->createQueryBuilder('invitation')
            ->select('COUNT(invitation.id)')
            ->leftJoin('invitation.rfq', 'rfq')
            ->leftJoin('invitation.tenant', 'tenant');

        if (null !== $status && '' !== trim($status)) {
            $qb
                ->andWhere('invitation.status = :status')
                ->setParameter('status', trim($status));
        }

        if (null !== $search && '' !== trim($search)) {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $qb
                ->andWhere(
                    $qb->expr()->orX(
                        'LOWER(tenant.name) LIKE :term',
                        'LOWER(rfq.externalReference) LIKE :term',
                        'LOWER(rfq.addressLine1) LIKE :term',
                        'LOWER(COALESCE(rfq.customerName, \'\')) LIKE :term',
                    ),
                )
                ->setParameter('term', $term);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<RfqInvitation> */
    public function findByRfqForOperatorDashboard(Rfq $rfq): array
    {
        return $this->createQueryBuilder('invitation')
            ->leftJoin('invitation.tenant', 'tenant')->addSelect('tenant')
            ->andWhere('invitation.rfq = :rfq')
            ->setParameter('rfq', $rfq)
            ->orderBy('invitation.updatedAt', 'DESC')
            ->addOrderBy('tenant.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countInvitedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('invitation')
            ->select('COUNT(invitation.id)')
            ->andWhere('invitation.invitedAt >= :from')
            ->andWhere('invitation.invitedAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<int> $tenantIds
     * @return list<RfqInvitation>
     */
    public function findForVendorAnalyticsByTenantIds(array $tenantIds): array
    {
        if ([] === $tenantIds) {
            return [];
        }

        return $this->createQueryBuilder('invitation')
            ->leftJoin('invitation.tenant', 'tenant')->addSelect('tenant')
            ->leftJoin('invitation.rfq', 'rfq')->addSelect('rfq')
            ->leftJoin('invitation.createdEstimate', 'estimate')->addSelect('estimate')
            ->andWhere('tenant.id IN (:tenantIds)')
            ->setParameter('tenantIds', $tenantIds)
            ->orderBy('tenant.name', 'ASC')
            ->addOrderBy('invitation.invitedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
