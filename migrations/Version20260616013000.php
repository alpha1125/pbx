<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616013000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unified outbound call fields to call_session.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE call_session ADD csr_user_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE call_session ADD call_mode VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE call_session ADD call_state VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE call_session ADD recording_state VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE call_session ADD transcription_state VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE call_session ADD client_phone_number VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_call_session_call_mode ON call_session (call_mode)');
        $this->addSql('CREATE INDEX idx_call_session_call_state ON call_session (call_state)');
        $this->addSql('CREATE INDEX idx_call_session_recording_state ON call_session (recording_state)');
        $this->addSql('CREATE INDEX idx_call_session_csr_user ON call_session (csr_user_id)');
        $this->addSql('ALTER TABLE call_session ADD CONSTRAINT FK_5E14CE164A1F9C72 FOREIGN KEY (csr_user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE call_session DROP CONSTRAINT FK_5E14CE164A1F9C72');
        $this->addSql('DROP INDEX idx_call_session_csr_user');
        $this->addSql('DROP INDEX idx_call_session_recording_state');
        $this->addSql('DROP INDEX idx_call_session_call_state');
        $this->addSql('DROP INDEX idx_call_session_call_mode');
        $this->addSql('ALTER TABLE call_session DROP COLUMN IF EXISTS client_phone_number');
        $this->addSql('ALTER TABLE call_session DROP COLUMN IF EXISTS transcription_state');
        $this->addSql('ALTER TABLE call_session DROP COLUMN IF EXISTS recording_state');
        $this->addSql('ALTER TABLE call_session DROP COLUMN IF EXISTS call_state');
        $this->addSql('ALTER TABLE call_session DROP COLUMN IF EXISTS call_mode');
        $this->addSql('ALTER TABLE call_session DROP COLUMN IF EXISTS csr_user_id');
    }
}
