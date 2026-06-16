<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CRM phase 5B billing output and reminder metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD COLUMN IF NOT EXISTS sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD COLUMN IF NOT EXISTS last_reminder_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD COLUMN IF NOT EXISTS reminder_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP COLUMN IF EXISTS sent_at');
        $this->addSql('ALTER TABLE invoice DROP COLUMN IF EXISTS last_reminder_at');
        $this->addSql('ALTER TABLE invoice DROP COLUMN IF EXISTS reminder_count');
    }
}
