<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Job;
use App\Entity\Task;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Property;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Task> */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    public function findOneByTenantAndId(Tenant $tenant, int $id): ?Task
    {
        return $this->findOneBy(['tenant' => $tenant, 'id' => $id]);
    }

    /** @return list<Task> */
    public function findByJob(Job $job): array
    {
        return $this->createQueryBuilder('task')
            ->leftJoin('task.assignedTo', 'assignedTo')->addSelect('assignedTo')
            ->andWhere('task.job = :job')
            ->setParameter('job', $job)
            ->orderBy('task.scheduledStartAt', 'ASC')
            ->addOrderBy('task.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Task> */
    public function findAssignedToUser(Tenant $tenant, User $user): array
    {
        return $this->createQueryBuilder('task')
            ->leftJoin('task.job', 'job')->addSelect('job')
            ->leftJoin('task.assignedTo', 'assignedTo')->addSelect('assignedTo')
            ->andWhere('task.tenant = :tenant')
            ->andWhere('task.assignedTo = :user')
            ->andWhere('task.status IN (:openStatuses)')
            ->setParameter('tenant', $tenant)
            ->setParameter('user', $user)
            ->setParameter('openStatuses', [Task::STATUS_UNSCHEDULED, Task::STATUS_SCHEDULED, Task::STATUS_IN_PROGRESS])
            ->orderBy('task.scheduledStartAt', 'ASC')
            ->addOrderBy('task.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Task> */
    public function findFollowUpsByJob(Job $job): array
    {
        return $this->createQueryBuilder('task')
            ->leftJoin('task.assignedTo', 'assignedTo')->addSelect('assignedTo')
            ->andWhere('task.job = :job')
            ->andWhere('task.kind IN (:kinds)')
            ->setParameter('job', $job)
            ->setParameter('kinds', [Task::KIND_FOLLOW_UP, Task::KIND_SERVICE_REMINDER])
            ->orderBy('task.scheduledStartAt', 'ASC')
            ->addOrderBy('task.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Task> */
    public function findFollowUpsByProperty(Property $property): array
    {
        return $this->createQueryBuilder('task')
            ->leftJoin('task.job', 'job')->addSelect('job')
            ->leftJoin('task.assignedTo', 'assignedTo')->addSelect('assignedTo')
            ->andWhere('job.property = :property')
            ->andWhere('task.kind IN (:kinds)')
            ->setParameter('property', $property)
            ->setParameter('kinds', [Task::KIND_FOLLOW_UP, Task::KIND_SERVICE_REMINDER])
            ->orderBy('task.scheduledStartAt', 'ASC')
            ->addOrderBy('task.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
