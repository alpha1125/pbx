<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260614152834 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow transcription jobs and transcripts without recordings and link them to call legs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE call_transcript ADD call_leg_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE call_transcript ALTER call_recording_id DROP NOT NULL');
        $this->addSql('ALTER TABLE call_transcript ADD CONSTRAINT FK_8C695659650F9405 FOREIGN KEY (call_leg_id) REFERENCES call_leg (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8C695659650F9405 ON call_transcript (call_leg_id)');
        $this->addSql('ALTER TABLE transcription_job ADD call_leg_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE transcription_job ALTER call_recording_id DROP NOT NULL');
        $this->addSql('ALTER TABLE transcription_job ADD CONSTRAINT FK_6798A7D7650F9405 FOREIGN KEY (call_leg_id) REFERENCES call_leg (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_6798A7D7650F9405 ON transcription_job (call_leg_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE call_transcript DROP CONSTRAINT FK_8C695659650F9405');
        $this->addSql('DROP INDEX IDX_8C695659650F9405');
        $this->addSql('ALTER TABLE call_transcript DROP call_leg_id');
        $this->addSql('ALTER TABLE call_transcript ALTER call_recording_id SET NOT NULL');
        $this->addSql('ALTER TABLE transcription_job DROP CONSTRAINT FK_6798A7D7650F9405');
        $this->addSql('DROP INDEX IDX_6798A7D7650F9405');
        $this->addSql('ALTER TABLE transcription_job DROP call_leg_id');
        $this->addSql('ALTER TABLE transcription_job ALTER call_recording_id SET NOT NULL');
    }
}
