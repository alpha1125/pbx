<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add telnyxConnectionId to browser_softphone_session for outbound dial routing.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE browser_softphone_session ADD telnyx_connection_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_browser_softphone_session_telnyx_conn ON browser_softphone_session (telnyx_connection_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_browser_softphone_session_telnyx_conn ON browser_softphone_session');
        $this->addSql('ALTER TABLE browser_softphone_session DROP telnyx_connection_id');
    }
}
