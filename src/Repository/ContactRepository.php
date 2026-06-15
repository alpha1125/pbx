<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Contact> */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /** @return list<Contact> */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant], ['displayName' => 'ASC']);
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?Contact
    {
        return $this->findOneBy(['tenant' => $tenant, 'id' => $id]);
    }

    public function findOneForRfqCustomer(Tenant $tenant, ?string $email, ?string $phone, ?string $displayName): ?Contact
    {
        $qb = $this->createQueryBuilder('contact')
            ->andWhere('contact.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->setMaxResults(1);

        if (null !== $email && '' !== trim($email)) {
            $match = clone $qb;
            $result = $match
                ->andWhere('LOWER(contact.primaryEmail) = :email')
                ->setParameter('email', mb_strtolower(trim($email)))
                ->getQuery()
                ->getOneOrNullResult();
            if (null !== $result) {
                return $result;
            }
        }

        if (null !== $phone && '' !== trim($phone)) {
            $match = clone $qb;
            $result = $match
                ->andWhere('contact.primaryPhone = :phone')
                ->setParameter('phone', trim($phone))
                ->getQuery()
                ->getOneOrNullResult();
            if (null !== $result) {
                return $result;
            }
        }

        if (null !== $displayName && '' !== trim($displayName)) {
            return $qb
                ->andWhere('LOWER(contact.displayName) = :displayName')
                ->setParameter('displayName', mb_strtolower(trim($displayName)))
                ->getQuery()
                ->getOneOrNullResult();
        }

        return null;
    }
}
