<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CRM phase 5A billing and payment domain core';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD COLUMN IF NOT EXISTS payment_instructions TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD COLUMN IF NOT EXISTS voided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD COLUMN IF NOT EXISTS void_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_line_item ADD COLUMN IF NOT EXISTS section_label VARCHAR(255) DEFAULT NULL');

        $this->addSql('CREATE TABLE payment (id BIGSERIAL NOT NULL, tenant_id BIGINT NOT NULL, payment_number VARCHAR(64) NOT NULL, kind VARCHAR(20) NOT NULL, received_at DATE NOT NULL, amount_cents INT NOT NULL, method VARCHAR(50) DEFAULT NULL, reference VARCHAR(255) DEFAULT NULL, memo TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_number ON payment (payment_number)');
        $this->addSql('CREATE INDEX idx_payment_tenant ON payment (tenant_id)');
        $this->addSql('CREATE INDEX idx_payment_kind ON payment (kind)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6B31C0F74EEC85D FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE payment_allocation (id BIGSERIAL NOT NULL, tenant_id BIGINT NOT NULL, payment_id BIGINT NOT NULL, invoice_id BIGINT NOT NULL, amount_cents INT NOT NULL, allocated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_payment_allocation_tenant ON payment_allocation (tenant_id)');
        $this->addSql('CREATE INDEX idx_payment_allocation_payment ON payment_allocation (payment_id)');
        $this->addSql('CREATE INDEX idx_payment_allocation_invoice ON payment_allocation (invoice_id)');
        $this->addSql('ALTER TABLE payment_allocation ADD CONSTRAINT FK_7D4A7D1B4EEC85D FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payment_allocation ADD CONSTRAINT FK_7D4A7D1BD6E1A7B8 FOREIGN KEY (payment_id) REFERENCES payment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payment_allocation ADD CONSTRAINT FK_7D4A7D1B989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_allocation DROP CONSTRAINT FK_7D4A7D1B4EEC85D');
        $this->addSql('ALTER TABLE payment_allocation DROP CONSTRAINT FK_7D4A7D1BD6E1A7B8');
        $this->addSql('ALTER TABLE payment_allocation DROP CONSTRAINT FK_7D4A7D1B989F1FD');
        $this->addSql('DROP TABLE payment_allocation');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6B31C0F74EEC85D');
        $this->addSql('DROP TABLE payment');
        $this->addSql('ALTER TABLE invoice_line_item DROP COLUMN section_label');
        $this->addSql('ALTER TABLE invoice DROP COLUMN notes');
        $this->addSql('ALTER TABLE invoice DROP COLUMN payment_instructions');
        $this->addSql('ALTER TABLE invoice DROP COLUMN voided_at');
        $this->addSql('ALTER TABLE invoice DROP COLUMN void_reason');
    }
}
