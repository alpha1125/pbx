<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CRM phase 5E accounting boundary retry state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_accounting_sync_record ADD COLUMN IF NOT EXISTS retry_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE invoice_accounting_sync_record ADD COLUMN IF NOT EXISTS last_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_accounting_sync_record ADD COLUMN IF NOT EXISTS next_retry_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_invoice_accounting_sync_record_next_retry_at ON invoice_accounting_sync_record (next_retry_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_invoice_accounting_sync_record_next_retry_at');
        $this->addSql('ALTER TABLE invoice_accounting_sync_record DROP COLUMN IF EXISTS retry_count');
        $this->addSql('ALTER TABLE invoice_accounting_sync_record DROP COLUMN IF EXISTS last_attempt_at');
        $this->addSql('ALTER TABLE invoice_accounting_sync_record DROP COLUMN IF EXISTS next_retry_at');
    }
}
