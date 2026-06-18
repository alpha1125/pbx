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

    /**
     * @return list<Job>
     */
    public function findByProperty(\App\Entity\Property $property, int $limit = 20): array
    {
        return $this->createQueryBuilder('job')
            ->leftJoin('job.contact', 'contact')->addSelect('contact')
            ->leftJoin('job.quote', 'quote')->addSelect('quote')
            ->leftJoin('job.invoice', 'invoice')->addSelect('invoice')
            ->leftJoin('job.equipment', 'equipment')->addSelect('equipment')
            ->leftJoin('job.assignedTo', 'assignedTo')->addSelect('assignedTo')
            ->andWhere('job.property = :property')
            ->setParameter('property', $property)
            ->orderBy('job.completedAt', 'DESC')
            ->addOrderBy('job.scheduledStartAt', 'DESC')
            ->addOrderBy('job.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneByQuote(\App\Entity\Quote $quote): ?Job
    {
        return $this->findOneBy(['quote' => $quote]);
    }

    public function findLatestCompletedAtByProperty(\App\Entity\Property $property): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('job')
            ->select('MAX(job.completedAt) AS completedAt')
            ->andWhere('job.property = :property')
            ->andWhere('job.status = :status')
            ->setParameter('property', $property)
            ->setParameter('status', Job::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        if ($result instanceof \DateTimeImmutable) {
            return $result;
        }

        return '' !== (string) $result ? new \DateTimeImmutable((string) $result) : null;
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

    public function countCompletedBetweenForTenant(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.tenant = :tenant')
            ->andWhere('job.completedAt IS NOT NULL')
            ->andWhere('job.completedAt >= :from')
            ->andWhere('job.completedAt < :to')
            ->setParameter('tenant', $tenant)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAssignedBetweenForTenant(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.tenant = :tenant')
            ->andWhere('job.assignedAt IS NOT NULL')
            ->andWhere('job.assignedAt >= :from')
            ->andWhere('job.assignedAt < :to')
            ->setParameter('tenant', $tenant)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<array{userId:int|null,userLabel:string,jobCount:int}>
     */
    public function findCompletedByAssigneeBetween(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 5): array
    {
        return array_map(static fn (array $row): array => [
            'userId' => isset($row['userId']) ? (int) $row['userId'] : null,
            'userLabel' => (string) $row['userLabel'],
            'jobCount' => (int) $row['jobCount'],
        ], $this->createQueryBuilder('job')
            ->select('IDENTITY(job.assignedTo) AS userId, COALESCE(assignedTo.displayName, \'Unassigned\') AS userLabel, COUNT(job.id) AS jobCount')
            ->leftJoin('job.assignedTo', 'assignedTo')
            ->andWhere('job.tenant = :tenant')
            ->andWhere('job.completedAt IS NOT NULL')
            ->andWhere('job.completedAt >= :from')
            ->andWhere('job.completedAt < :to')
            ->setParameter('tenant', $tenant)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('job.assignedTo, assignedTo.id, assignedTo.displayName')
            ->orderBy('jobCount', 'DESC')
            ->addOrderBy('userLabel', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * @return list<array{userId:int|null,userLabel:string,jobCount:int}>
     */
    public function findAssignedByAssigneeBetween(Tenant $tenant, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 5): array
    {
        return array_map(static fn (array $row): array => [
            'userId' => isset($row['userId']) ? (int) $row['userId'] : null,
            'userLabel' => (string) $row['userLabel'],
            'jobCount' => (int) $row['jobCount'],
        ], $this->createQueryBuilder('job')
            ->select('IDENTITY(job.assignedTo) AS userId, COALESCE(assignedTo.displayName, \'Unassigned\') AS userLabel, COUNT(job.id) AS jobCount')
            ->leftJoin('job.assignedTo', 'assignedTo')
            ->andWhere('job.tenant = :tenant')
            ->andWhere('job.assignedAt IS NOT NULL')
            ->andWhere('job.assignedAt >= :from')
            ->andWhere('job.assignedAt < :to')
            ->setParameter('tenant', $tenant)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('job.assignedTo, assignedTo.id, assignedTo.displayName')
            ->orderBy('jobCount', 'DESC')
            ->addOrderBy('userLabel', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult());
    }
}
