<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616011000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add RFQ invitation lifecycle and reminder metadata.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rfq_invitation ADD invited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE rfq_invitation ADD viewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE rfq_invitation ADD expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE rfq_invitation ADD expired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE rfq_invitation ADD reminder_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE rfq_invitation ADD reminder_notes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE rfq_invitation ADD CONSTRAINT uniq_rfq_invitation_tenant_rfq UNIQUE (tenant_id, rfq_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rfq_invitation DROP CONSTRAINT uniq_rfq_invitation_tenant_rfq');
        $this->addSql('ALTER TABLE rfq_invitation DROP reminder_notes');
        $this->addSql('ALTER TABLE rfq_invitation DROP reminder_at');
        $this->addSql('ALTER TABLE rfq_invitation DROP expired_at');
        $this->addSql('ALTER TABLE rfq_invitation DROP expires_at');
        $this->addSql('ALTER TABLE rfq_invitation DROP viewed_at');
        $this->addSql('ALTER TABLE rfq_invitation DROP invited_at');
    }
}
