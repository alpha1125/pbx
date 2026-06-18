<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616016000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist browser outbound call identifiers and lifecycle state.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE call_session ADD browser_call_id VARCHAR(255) DEFAULT NULL');
        $this->addSql("ALTER TABLE browser_softphone_session ADD call_id VARCHAR(255) DEFAULT NULL");
        $this->addSql('ALTER TABLE browser_softphone_session ADD call_state VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD call_error_code VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD call_error_message VARCHAR(1000) DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD call_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD call_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD call_answered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD call_ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE browser_softphone_session ADD call_failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_call_session_browser_call_id ON call_session (browser_call_id)');
        $this->addSql('CREATE INDEX idx_browser_softphone_session_call_id ON browser_softphone_session (call_id)');
        $this->addSql('CREATE INDEX idx_browser_softphone_session_call_state ON browser_softphone_session (call_state)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_browser_softphone_session_call_state');
        $this->addSql('DROP INDEX idx_browser_softphone_session_call_id');
        $this->addSql('DROP INDEX idx_call_session_browser_call_id');
        $this->addSql('ALTER TABLE browser_softphone_session DROP call_failed_at');
        $this->addSql('ALTER TABLE browser_softphone_session DROP call_ended_at');
        $this->addSql('ALTER TABLE browser_softphone_session DROP call_answered_at');
        $this->addSql('ALTER TABLE browser_softphone_session DROP call_started_at');
        $this->addSql('ALTER TABLE browser_softphone_session DROP call_meta');
        $this->addSql('ALTER TABLE browser_softphone_session DROP call_error_message');
        $this->addSql('ALTER TABLE browser_softphone_session DROP call_error_code');
        $this->addSql('ALTER TABLE browser_softphone_session DROP call_state');
        $this->addSql('ALTER TABLE browser_softphone_session DROP call_id');
        $this->addSql('ALTER TABLE call_session DROP browser_call_id');
    }
}
