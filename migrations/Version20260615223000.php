<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CRM phase 5D accounting integration boundary model';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS invoice_accounting_sync_record (id BIGSERIAL NOT NULL, invoice_id BIGINT NOT NULL, provider VARCHAR(50) NOT NULL, status VARCHAR(50) NOT NULL, external_id VARCHAR(255) DEFAULT NULL, external_number VARCHAR(255) DEFAULT NULL, exported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error_message TEXT DEFAULT NULL, error_context JSONB DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_invoice_accounting_sync_record_invoice_provider ON invoice_accounting_sync_record (invoice_id, provider)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_invoice_accounting_sync_record_invoice ON invoice_accounting_sync_record (invoice_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_invoice_accounting_sync_record_provider ON invoice_accounting_sync_record (provider)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_invoice_accounting_sync_record_status ON invoice_accounting_sync_record (status)');
        $this->addSql('ALTER TABLE invoice_accounting_sync_record ADD CONSTRAINT FK_1C3D8A6D989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_accounting_sync_record DROP CONSTRAINT IF EXISTS FK_1C3D8A6D989F1FD');
        $this->addSql('DROP TABLE IF EXISTS invoice_accounting_sync_record');
    }
}
