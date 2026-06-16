<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\Invoice;
use App\Entity\Property;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditLog> */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /** @return list<AuditLog> */
    public function findRecentByProperty(Tenant $tenant, Property $property, int $limit = 50): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT id
                FROM audit_log
                WHERE tenant_id = :tenantId
                  AND (
                    (entity_type = :entityType AND entity_id = :entityId)
                    OR metadata @> :metadataNeedle
                  )
                ORDER BY created_at DESC
                LIMIT :limit
                SQL,
            [
                'tenantId' => $tenant->getId(),
                'entityType' => 'property',
                'entityId' => (string) $property->getId(),
                'metadataNeedle' => json_encode(['propertyId' => $property->getId()], JSON_THROW_ON_ERROR),
                'limit' => $limit,
            ],
            [
                'tenantId' => \Doctrine\DBAL\ParameterType::INTEGER,
                'entityType' => \Doctrine\DBAL\ParameterType::STRING,
                'entityId' => \Doctrine\DBAL\ParameterType::STRING,
                'metadataNeedle' => \Doctrine\DBAL\ParameterType::STRING,
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            ],
        )->fetchFirstColumn();

        if ([] === $rows) {
            return [];
        }

        $logs = $this->findBy(['id' => array_map('intval', $rows)]);
        $byId = [];
        foreach ($logs as $log) {
            $byId[$log->getId()] = $log;
        }

        $ordered = [];
        foreach ($rows as $id) {
            if (isset($byId[(int) $id])) {
                $ordered[] = $byId[(int) $id];
            }
        }

        return $ordered;
    }

    /** @return list<AuditLog> */
    public function findRecentByInvoice(Invoice $invoice, int $limit = 20): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT id
                FROM audit_log
                WHERE tenant_id = :tenantId
                  AND entity_type = :entityType
                  AND entity_id = :entityId
                  AND action LIKE :actionPrefix
                ORDER BY created_at DESC
                LIMIT :limit
                SQL,
            [
                'tenantId' => $invoice->getTenant()->getId(),
                'entityType' => 'invoice',
                'entityId' => $invoice->getInvoiceNumber(),
                'actionPrefix' => 'invoice.accounting\\_%',
                'limit' => $limit,
            ],
            [
                'tenantId' => \Doctrine\DBAL\ParameterType::INTEGER,
                'entityType' => \Doctrine\DBAL\ParameterType::STRING,
                'entityId' => \Doctrine\DBAL\ParameterType::STRING,
                'actionPrefix' => \Doctrine\DBAL\ParameterType::STRING,
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            ],
        )->fetchFirstColumn();

        if ([] === $rows) {
            return [];
        }

        $logs = $this->findBy(['id' => array_map('intval', $rows)]);
        $byId = [];
        foreach ($logs as $log) {
            $byId[$log->getId()] = $log;
        }

        $ordered = [];
        foreach ($rows as $id) {
            if (isset($byId[(int) $id])) {
                $ordered[] = $byId[(int) $id];
            }
        }

        return $ordered;
    }
}
