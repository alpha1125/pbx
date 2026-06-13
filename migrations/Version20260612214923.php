<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612214923 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store Telnyx payloads as JSONB and enforce provider event id uniqueness';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telnyx_event ALTER payload TYPE JSONB');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4EACAB3939B58662 ON telnyx_event (provider_event_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_4EACAB3939B58662');
        $this->addSql('ALTER TABLE telnyx_event ALTER payload TYPE JSON');
    }
}
