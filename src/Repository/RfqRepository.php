<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Property;
use App\Entity\Rfq;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Rfq> */
class RfqRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rfq::class);
    }

    public function findOneByExternalReference(string $externalReference): ?Rfq
    {
        $externalReference = trim($externalReference);
        if ('' === $externalReference) {
            return null;
        }

        return $this->findOneBy(['externalReference' => $externalReference]);
    }

    public function findDuplicateForIntake(Rfq $rfq): ?Rfq
    {
        $externalReference = $rfq->getExternalReference();
        if (null !== $externalReference) {
            $existing = $this->findOneByExternalReference($externalReference);
            if (null !== $existing) {
                return $existing;
            }
        }

        $qb = $this->createQueryBuilder('rfq')
            ->andWhere('LOWER(rfq.addressLine1) = :addressLine1')
            ->andWhere('COALESCE(LOWER(rfq.addressLine2), \'\') = :addressLine2')
            ->andWhere('LOWER(rfq.city) = :city')
            ->andWhere('LOWER(rfq.province) = :province')
            ->andWhere('UPPER(rfq.postalCode) = :postalCode')
            ->andWhere('UPPER(rfq.country) = :country')
            ->setParameter('addressLine1', mb_strtolower(trim($rfq->getAddressLine1())))
            ->setParameter('addressLine2', null !== $rfq->getAddressLine2() ? mb_strtolower(trim($rfq->getAddressLine2())) : '')
            ->setParameter('city', mb_strtolower(trim($rfq->getCity())))
            ->setParameter('province', mb_strtolower(trim($rfq->getProvince())))
            ->setParameter('postalCode', strtoupper(trim($rfq->getPostalCode())))
            ->setParameter('country', strtoupper(trim($rfq->getCountry())))
            ->setMaxResults(1);

        $customerConditions = [];
        if (null !== $rfq->getCustomerEmail()) {
            $customerConditions[] = 'LOWER(rfq.customerEmail) = :customerEmail';
            $qb->setParameter('customerEmail', mb_strtolower(trim($rfq->getCustomerEmail())));
        }

        if (null !== $rfq->getCustomerPhone()) {
            $customerConditions[] = 'rfq.customerPhone = :customerPhone';
            $qb->setParameter('customerPhone', trim($rfq->getCustomerPhone()));
        }

        if (null !== $rfq->getCustomerName()) {
            $customerConditions[] = 'LOWER(rfq.customerName) = :customerName';
            $qb->setParameter('customerName', mb_strtolower(trim($rfq->getCustomerName())));
        }

        if ([] !== $customerConditions) {
            $qb->andWhere(sprintf('(%s)', implode(' OR ', $customerConditions)));
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return list<Rfq>
     */
    public function findByPropertyAddress(Property $property, int $limit = 10): array
    {
        return $this->createQueryBuilder('rfq')
            ->andWhere('LOWER(rfq.addressLine1) = :addressLine1')
            ->andWhere('COALESCE(LOWER(rfq.addressLine2), \'\') = :addressLine2')
            ->andWhere('LOWER(rfq.city) = :city')
            ->andWhere('LOWER(rfq.province) = :province')
            ->andWhere('UPPER(rfq.postalCode) = :postalCode')
            ->andWhere('UPPER(rfq.country) = :country')
            ->setParameter('addressLine1', mb_strtolower(trim($property->getAddressLine1())))
            ->setParameter('addressLine2', null !== $property->getAddressLine2() ? mb_strtolower(trim($property->getAddressLine2())) : '')
            ->setParameter('city', mb_strtolower(trim($property->getCity())))
            ->setParameter('province', mb_strtolower(trim($property->getProvince())))
            ->setParameter('postalCode', strtoupper(trim($property->getPostalCode())))
            ->setParameter('country', strtoupper(trim($property->getCountry())))
            ->orderBy('rfq.createdAt', 'DESC')
            ->addOrderBy('rfq.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Rfq>
     */
    public function findForOperatorDashboard(?string $status, ?string $search, int $page, int $pageSize): array
    {
        $qb = $this->createQueryBuilder('rfq')
            ->orderBy('rfq.updatedAt', 'DESC')
            ->addOrderBy('rfq.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $pageSize))
            ->setMaxResults($pageSize);

        if (null !== $status && '' !== trim($status)) {
            $qb
                ->andWhere('rfq.status = :status')
                ->setParameter('status', trim($status));
        }

        if (null !== $search && '' !== trim($search)) {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $qb
                ->andWhere(
                    $qb->expr()->orX(
                        'LOWER(rfq.externalReference) LIKE :term',
                        'LOWER(rfq.addressLine1) LIKE :term',
                        'LOWER(COALESCE(rfq.addressLine2, \'\')) LIKE :term',
                        'LOWER(rfq.city) LIKE :term',
                        'LOWER(rfq.province) LIKE :term',
                        'LOWER(rfq.postalCode) LIKE :term',
                        'LOWER(COALESCE(rfq.customerName, \'\')) LIKE :term',
                        'LOWER(COALESCE(rfq.customerEmail, \'\')) LIKE :term',
                        'LOWER(COALESCE(rfq.customerPhone, \'\')) LIKE :term',
                    ),
                )
                ->setParameter('term', $term);
        }

        return $qb->getQuery()->getResult();
    }

    public function countForOperatorDashboard(?string $status, ?string $search): int
    {
        $qb = $this->createQueryBuilder('rfq')
            ->select('COUNT(rfq.id)');

        if (null !== $status && '' !== trim($status)) {
            $qb
                ->andWhere('rfq.status = :status')
                ->setParameter('status', trim($status));
        }

        if (null !== $search && '' !== trim($search)) {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $qb
                ->andWhere(
                    $qb->expr()->orX(
                        'LOWER(rfq.externalReference) LIKE :term',
                        'LOWER(rfq.addressLine1) LIKE :term',
                        'LOWER(COALESCE(rfq.addressLine2, \'\')) LIKE :term',
                        'LOWER(rfq.city) LIKE :term',
                        'LOWER(rfq.province) LIKE :term',
                        'LOWER(rfq.postalCode) LIKE :term',
                        'LOWER(COALESCE(rfq.customerName, \'\')) LIKE :term',
                        'LOWER(COALESCE(rfq.customerEmail, \'\')) LIKE :term',
                        'LOWER(COALESCE(rfq.customerPhone, \'\')) LIKE :term',
                    ),
                )
                ->setParameter('term', $term);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countCreatedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('rfq')
            ->select('COUNT(rfq.id)')
            ->andWhere('rfq.createdAt >= :from')
            ->andWhere('rfq.createdAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
