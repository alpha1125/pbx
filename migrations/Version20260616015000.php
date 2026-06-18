<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616015000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist browser softphone SDK connection lifecycle state.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE browser_softphone_session ADD connection_state VARCHAR(50) NOT NULL DEFAULT 'idle'");
        $this->addSql('ALTER TABLE browser_softphone_session ADD connection_error_code VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD connection_error_message VARCHAR(1000) DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD connection_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD connection_attempted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD connection_ready_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD connection_failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE browser_softphone_session DROP connection_failed_at');
        $this->addSql('ALTER TABLE browser_softphone_session DROP connection_ready_at');
        $this->addSql('ALTER TABLE browser_softphone_session DROP connection_attempted_at');
        $this->addSql('ALTER TABLE browser_softphone_session DROP connection_meta');
        $this->addSql('ALTER TABLE browser_softphone_session DROP connection_error_message');
        $this->addSql('ALTER TABLE browser_softphone_session DROP connection_error_code');
        $this->addSql('ALTER TABLE browser_softphone_session DROP connection_state');
    }
}
