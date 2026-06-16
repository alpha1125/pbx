<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616004000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CRM phase 6B assignment fields for jobs and tasks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job ADD COLUMN IF NOT EXISTS assigned_to_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE job ADD COLUMN IF NOT EXISTS assigned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_job_assigned_to ON job (assigned_to_id)');
        $this->addSql('ALTER TABLE job ADD CONSTRAINT FK_6F6A8D4F69D1B32F FOREIGN KEY (assigned_to_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE task ADD COLUMN IF NOT EXISTS assigned_to_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD COLUMN IF NOT EXISTS assigned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_task_assigned_to ON task (assigned_to_id)');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_7A4A7D1B69D1B32F FOREIGN KEY (assigned_to_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP CONSTRAINT IF EXISTS FK_7A4A7D1B69D1B32F');
        $this->addSql('DROP INDEX IF EXISTS idx_task_assigned_to');
        $this->addSql('ALTER TABLE task DROP COLUMN IF EXISTS assigned_at');
        $this->addSql('ALTER TABLE task DROP COLUMN IF EXISTS assigned_to_id');

        $this->addSql('ALTER TABLE job DROP CONSTRAINT IF EXISTS FK_6F6A8D4F69D1B32F');
        $this->addSql('DROP INDEX IF EXISTS idx_job_assigned_to');
        $this->addSql('ALTER TABLE job DROP COLUMN IF EXISTS assigned_at');
        $this->addSql('ALTER TABLE job DROP COLUMN IF EXISTS assigned_to_id');
    }
}
