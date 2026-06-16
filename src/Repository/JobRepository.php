<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Job> */
class JobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Job::class);
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?Job
    {
        return $this->findOneBy(['tenant' => $tenant, 'id' => $id]);
    }

    public function findOneByQuote(\App\Entity\Quote $quote): ?Job
    {
        return $this->findOneBy(['quote' => $quote]);
    }

    /** @return list<Job> */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('job')
            ->leftJoin('job.property', 'property')->addSelect('property')
            ->leftJoin('job.contact', 'contact')->addSelect('contact')
            ->leftJoin('job.quote', 'quote')->addSelect('quote')
            ->leftJoin('job.invoice', 'invoice')->addSelect('invoice')
            ->leftJoin('job.equipment', 'equipment')->addSelect('equipment')
            ->leftJoin('job.assignedTo', 'assignedTo')->addSelect('assignedTo')
            ->andWhere('job.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('job.scheduledStartAt', 'ASC')
            ->addOrderBy('job.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Job> */
    public function findAssignedToUser(Tenant $tenant, User $user): array
    {
        return $this->createQueryBuilder('job')
            ->leftJoin('job.property', 'property')->addSelect('property')
            ->leftJoin('job.contact', 'contact')->addSelect('contact')
            ->leftJoin('job.equipment', 'equipment')->addSelect('equipment')
            ->leftJoin('job.assignedTo', 'assignedTo')->addSelect('assignedTo')
            ->andWhere('job.tenant = :tenant')
            ->andWhere('job.assignedTo = :user')
            ->andWhere('job.status IN (:openStatuses)')
            ->setParameter('tenant', $tenant)
            ->setParameter('user', $user)
            ->setParameter('openStatuses', [Job::STATUS_UNSCHEDULED, Job::STATUS_SCHEDULED, Job::STATUS_IN_PROGRESS])
            ->orderBy('job.scheduledStartAt', 'ASC')
            ->addOrderBy('job.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<int> $tenantIds
     * @return list<Job>
     */
    public function findForVendorAnalyticsByTenantIds(array $tenantIds): array
    {
        if ([] === $tenantIds) {
            return [];
        }

        return $this->createQueryBuilder('job')
            ->leftJoin('job.tenant', 'tenant')->addSelect('tenant')
            ->leftJoin('job.property', 'property')->addSelect('property')
            ->leftJoin('job.quote', 'quote')->addSelect('quote')
            ->andWhere('tenant.id IN (:tenantIds)')
            ->setParameter('tenantIds', $tenantIds)
            ->orderBy('tenant.name', 'ASC')
            ->addOrderBy('job.completedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countCompletedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.completedAt IS NOT NULL')
            ->andWhere('job.completedAt >= :from')
            ->andWhere('job.completedAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
