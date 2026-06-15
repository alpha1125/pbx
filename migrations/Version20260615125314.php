<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260615125314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contact ADD is_archived BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE contact ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE equipment ADD is_archived BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE equipment ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE property ADD is_archived BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE property ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contact DROP is_archived');
        $this->addSql('ALTER TABLE contact DROP archived_at');
        $this->addSql('ALTER TABLE equipment DROP is_archived');
        $this->addSql('ALTER TABLE equipment DROP archived_at');
        $this->addSql('ALTER TABLE property DROP is_archived');
        $this->addSql('ALTER TABLE property DROP archived_at');
    }
}
