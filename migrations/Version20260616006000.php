<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616006000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CRM phase 6D follow-up workflow fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job ADD COLUMN IF NOT EXISTS follow_up_generated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE job ADD COLUMN IF NOT EXISTS follow_up_summary TEXT DEFAULT NULL');

        $this->addSql('ALTER TABLE task ADD COLUMN IF NOT EXISTS kind VARCHAR(50) DEFAULT \'manual\' NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_task_kind ON task (kind)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_task_kind');
        $this->addSql('ALTER TABLE task DROP COLUMN IF EXISTS kind');
        $this->addSql('ALTER TABLE job DROP COLUMN IF EXISTS follow_up_generated_at');
        $this->addSql('ALTER TABLE job DROP COLUMN IF EXISTS follow_up_summary');
    }
}
