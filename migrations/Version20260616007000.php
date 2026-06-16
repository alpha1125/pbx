<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616007000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CRM phase 6E unresolved issue tracking fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job ADD COLUMN IF NOT EXISTS unresolved_issue_notes TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job DROP COLUMN IF EXISTS unresolved_issue_notes');
    }
}
