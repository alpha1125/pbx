<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616008000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CRM phase 6F service reminder fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job ADD COLUMN IF NOT EXISTS service_reminder_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE job ADD COLUMN IF NOT EXISTS service_reminder_notes TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job DROP COLUMN IF EXISTS service_reminder_notes');
        $this->addSql('ALTER TABLE job DROP COLUMN IF EXISTS service_reminder_at');
    }
}
