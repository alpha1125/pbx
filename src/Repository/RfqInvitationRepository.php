<?php

declare(strict_types=1);

namespace App\Repository;

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
}
