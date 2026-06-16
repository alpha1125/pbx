<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616009000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a unique RFQ external reference constraint.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rfq ADD CONSTRAINT uniq_rfq_external_reference UNIQUE (external_reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rfq DROP CONSTRAINT uniq_rfq_external_reference');
    }
}
