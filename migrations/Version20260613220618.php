<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613220618 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transcription_job ADD provider_job_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE transcription_job ADD provider_status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE transcription_job ADD provider_model VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE transcription_job ADD provider_config JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE transcription_job ADD submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE transcription_job ADD raw_provider_response JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE transcription_job ALTER provider SET DEFAULT \'telnyx\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transcription_job DROP provider_job_id');
        $this->addSql('ALTER TABLE transcription_job DROP provider_status');
        $this->addSql('ALTER TABLE transcription_job DROP provider_model');
        $this->addSql('ALTER TABLE transcription_job DROP provider_config');
        $this->addSql('ALTER TABLE transcription_job DROP submitted_at');
        $this->addSql('ALTER TABLE transcription_job DROP raw_provider_response');
        $this->addSql('ALTER TABLE transcription_job ALTER provider SET DEFAULT \'local_worker\'');
    }
}
