<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed>|null $beforeData
     * @param array<string, mixed>|null $afterData
     * @param array<string, mixed>|null $metadata
     */
    public function log(
        ?Tenant $tenant,
        string $entityType,
        string|int $entityId,
        string $action,
        ?array $beforeData = null,
        ?array $afterData = null,
        ?array $metadata = null,
    ): AuditLog {
        $audit = new AuditLog($entityType, (string) $entityId, $action);
        $audit->setTenant($tenant);
        $audit->setBeforeData($beforeData);
        $audit->setAfterData($afterData);
        $audit->setMetadata($metadata);

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $audit
                ->setActorUserId((string) $user->getId())
                ->setActorDisplay($user->getDisplayName());
        }

        $this->entityManager->persist($audit);

        return $audit;
    }
}
