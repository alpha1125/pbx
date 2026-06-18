<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add telnyxCallControlId to browser_softphone_session for browser call control reconciliation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE browser_softphone_session ADD telnyx_call_control_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_browser_softphone_session_telnyx_call_control ON browser_softphone_session (telnyx_call_control_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_browser_softphone_session_telnyx_call_control');
        $this->addSql('ALTER TABLE browser_softphone_session DROP telnyx_call_control_id');
    }
}
