<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616014000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add browser softphone session allocation table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE browser_softphone_session (
                id BIGSERIAL NOT NULL,
                call_session_id BIGINT NOT NULL,
                tenant_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                session_token VARCHAR(255) NOT NULL,
                status VARCHAR(50) NOT NULL,
                allocated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BROWSER_SOFTPHONE_SESSION_TOKEN ON browser_softphone_session (session_token)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BROWSER_SOFTPHONE_SESSION_CALL_SESSION ON browser_softphone_session (call_session_id)');
        $this->addSql('CREATE INDEX idx_browser_softphone_session_status ON browser_softphone_session (status)');
        $this->addSql('CREATE INDEX idx_browser_softphone_session_allocated_at ON browser_softphone_session (allocated_at)');
        $this->addSql('CREATE INDEX IDX_BROWSER_SOFTPHONE_SESSION_CALL_SESSION ON browser_softphone_session (call_session_id)');
        $this->addSql('CREATE INDEX IDX_BROWSER_SOFTPHONE_SESSION_TENANT ON browser_softphone_session (tenant_id)');
        $this->addSql('CREATE INDEX IDX_BROWSER_SOFTPHONE_SESSION_USER ON browser_softphone_session (user_id)');
        $this->addSql('ALTER TABLE browser_softphone_session ADD CONSTRAINT FK_BROWSER_SOFTPHONE_SESSION_CALL_SESSION FOREIGN KEY (call_session_id) REFERENCES call_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE browser_softphone_session ADD CONSTRAINT FK_BROWSER_SOFTPHONE_SESSION_TENANT FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE browser_softphone_session ADD CONSTRAINT FK_BROWSER_SOFTPHONE_SESSION_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE browser_softphone_session');
    }
}
