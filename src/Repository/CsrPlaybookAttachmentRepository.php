<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\CsrPlaybookAttachment;
use App\Entity\Property;
use App\Entity\RetentionOpportunity;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CsrPlaybookAttachment> */
class CsrPlaybookAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CsrPlaybookAttachment::class);
    }

    /**
     * @return list<CsrPlaybookAttachment>
     */
    public function findByTenantOrdered(Tenant $tenant): array
    {
        return $this->createQueryBuilder('attachment')
            ->leftJoin('attachment.property', 'property')->addSelect('property')
            ->leftJoin('attachment.contact', 'contact')->addSelect('contact')
            ->leftJoin('attachment.retentionOpportunity', 'opportunity')->addSelect('opportunity')
            ->andWhere('attachment.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('attachment.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<CsrPlaybookAttachment>
     */
    public function findByTenantAndProperty(Tenant $tenant, Property $property): array
    {
        return $this->createQueryBuilder('attachment')
            ->leftJoin('attachment.contact', 'contact')->addSelect('contact')
            ->leftJoin('attachment.retentionOpportunity', 'opportunity')->addSelect('opportunity')
            ->andWhere('attachment.tenant = :tenant')
            ->andWhere('attachment.property = :property')
            ->setParameter('tenant', $tenant)
            ->setParameter('property', $property)
            ->orderBy('attachment.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<CsrPlaybookAttachment>
     */
    public function findByTenantAndContact(Tenant $tenant, Contact $contact): array
    {
        return $this->createQueryBuilder('attachment')
            ->leftJoin('attachment.property', 'property')->addSelect('property')
            ->leftJoin('attachment.retentionOpportunity', 'opportunity')->addSelect('opportunity')
            ->andWhere('attachment.tenant = :tenant')
            ->andWhere('attachment.contact = :contact')
            ->setParameter('tenant', $tenant)
            ->setParameter('contact', $contact)
            ->orderBy('attachment.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<CsrPlaybookAttachment>
     */
    public function findByTenantAndOpportunity(Tenant $tenant, RetentionOpportunity $retentionOpportunity): array
    {
        return $this->createQueryBuilder('attachment')
            ->leftJoin('attachment.property', 'property')->addSelect('property')
            ->leftJoin('attachment.contact', 'contact')->addSelect('contact')
            ->andWhere('attachment.tenant = :tenant')
            ->andWhere('attachment.retentionOpportunity = :retentionOpportunity')
            ->setParameter('tenant', $tenant)
            ->setParameter('retentionOpportunity', $retentionOpportunity)
            ->orderBy('attachment.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByTenantContextAndType(
        Tenant $tenant,
        string $playbookType,
        ?Property $property = null,
        ?Contact $contact = null,
        ?RetentionOpportunity $retentionOpportunity = null,
    ): ?CsrPlaybookAttachment {
        $qb = $this->createQueryBuilder('attachment')
            ->andWhere('attachment.tenant = :tenant')
            ->andWhere('attachment.playbookType = :playbookType')
            ->setParameter('tenant', $tenant)
            ->setParameter('playbookType', $playbookType)
            ->setMaxResults(1);

        if (null !== $property) {
            $qb->andWhere('attachment.property = :property')->setParameter('property', $property);
        } else {
            $qb->andWhere('attachment.property IS NULL');
        }

        if (null !== $contact) {
            $qb->andWhere('attachment.contact = :contact')->setParameter('contact', $contact);
        } else {
            $qb->andWhere('attachment.contact IS NULL');
        }

        if (null !== $retentionOpportunity) {
            $qb->andWhere('attachment.retentionOpportunity = :retentionOpportunity')->setParameter('retentionOpportunity', $retentionOpportunity);
        } else {
            $qb->andWhere('attachment.retentionOpportunity IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
