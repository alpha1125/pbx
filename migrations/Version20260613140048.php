<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613140048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track Telnyx billed duration per leg and group forwarded sessions under the inbound call';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE call_leg ADD billed_duration_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE call_session ADD parent_call_session_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE call_session ADD CONSTRAINT FK_5E14CE1D26CA9B9 FOREIGN KEY (parent_call_session_id) REFERENCES call_session (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5E14CE1D26CA9B9 ON call_session (parent_call_session_id)');
        $this->addSql(<<<'SQL'
            UPDATE call_leg leg
            SET billed_duration_seconds = cost.billed_duration_seconds
            FROM (
                SELECT payload->'data'->'payload'->>'call_leg_id' AS provider_leg_id,
                       MAX((payload->'data'->'payload'->>'billed_duration_secs')::INT) AS billed_duration_seconds
                FROM telnyx_event
                WHERE event_type = 'call.cost'
                  AND payload->'data'->'payload'->>'billed_duration_secs' IS NOT NULL
                GROUP BY payload->'data'->'payload'->>'call_leg_id'
            ) cost
            WHERE leg.provider_leg_id = cost.provider_leg_id
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE call_session child
            SET parent_call_session_id = parent.id
            FROM telnyx_event event
            INNER JOIN call_session parent
                ON parent.provider_session_id = (
                    convert_from(
                        decode(event.payload->'data'->'payload'->>'client_state', 'base64'),
                        'UTF8'
                    )::JSONB->>'inbound_call_session_id'
                )
            WHERE event.event_type = 'call.cost'
              AND event.payload->'data'->'payload'->>'client_state' IS NOT NULL
              AND child.provider_session_id = event.payload->'data'->'payload'->>'call_session_id'
              AND child.id <> parent.id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE call_leg DROP billed_duration_seconds');
        $this->addSql('ALTER TABLE call_session DROP CONSTRAINT FK_5E14CE1D26CA9B9');
        $this->addSql('DROP INDEX IDX_5E14CE1D26CA9B9');
        $this->addSql('ALTER TABLE call_session DROP parent_call_session_id');
    }
}
