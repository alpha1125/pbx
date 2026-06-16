<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615243000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CRM phase 5G tenant invoice settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant ADD COLUMN IF NOT EXISTS invoice_due_days INT DEFAULT 30 NOT NULL');
        $this->addSql('ALTER TABLE tenant ADD COLUMN IF NOT EXISTS invoice_payment_instructions TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD COLUMN IF NOT EXISTS invoice_footer TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP COLUMN IF EXISTS invoice_due_days');
        $this->addSql('ALTER TABLE tenant DROP COLUMN IF EXISTS invoice_payment_instructions');
        $this->addSql('ALTER TABLE tenant DROP COLUMN IF EXISTS invoice_footer');
    }
}
