<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add communication timeline items for CRM history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE communication_timeline_item (id BIGSERIAL NOT NULL, tenant_id BIGINT NOT NULL, property_id BIGINT DEFAULT NULL, contact_id BIGINT DEFAULT NULL, estimate_id BIGINT DEFAULT NULL, quote_id BIGINT DEFAULT NULL, invoice_id BIGINT DEFAULT NULL, rfq_invitation_id BIGINT DEFAULT NULL, call_session_id BIGINT DEFAULT NULL, call_recording_id BIGINT DEFAULT NULL, call_transcript_id BIGINT DEFAULT NULL, call_summary_id BIGINT DEFAULT NULL, created_by_id BIGINT DEFAULT NULL, item_type VARCHAR(50) NOT NULL, source_key VARCHAR(190) DEFAULT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, body_text TEXT DEFAULT NULL, metadata JSON DEFAULT NULL, disposition VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_timeline_source_key ON communication_timeline_item (source_key)');
        $this->addSql('CREATE INDEX idx_timeline_tenant_property_occurred_at ON communication_timeline_item (tenant_id, property_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_timeline_item_type ON communication_timeline_item (item_type)');
        $this->addSql('CREATE INDEX IDX_54A4EA5B4EEC85D ON communication_timeline_item (tenant_id)');
        $this->addSql('CREATE INDEX IDX_54A4EA55492D206 ON communication_timeline_item (property_id)');
        $this->addSql('CREATE INDEX IDX_54A4EA5BE7A1254A ON communication_timeline_item (contact_id)');
        $this->addSql('CREATE INDEX IDX_54A4EA53CD04B2D ON communication_timeline_item (estimate_id)');
        $this->addSql('CREATE INDEX IDX_54A4EA5A27CB1C0 ON communication_timeline_item (quote_id)');
        $this->addSql('CREATE INDEX IDX_54A4EA52989F1FD ON communication_timeline_item (invoice_id)');
        $this->addSql('CREATE INDEX IDX_54A4EA5609B14E6 ON communication_timeline_item (rfq_invitation_id)');
        $this->addSql('CREATE INDEX IDX_54A4EA5C59F36BE ON communication_timeline_item (call_session_id)');
        $this->addSql('CREATE INDEX IDX_54A4EA5BA227DAB ON communication_timeline_item (call_recording_id)');
        $this->addSql('CREATE INDEX IDX_54A4EA57E707088 ON communication_timeline_item (call_transcript_id)');
        $this->addSql('CREATE INDEX IDX_54A4EA5F39B4E24 ON communication_timeline_item (call_summary_id)');
        $this->addSql('CREATE INDEX IDX_54A4EA5B03A8386 ON communication_timeline_item (created_by_id)');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA5B4EEC85D FOREIGN KEY (tenant_id) REFERENCES tenant (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA55492D206 FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA5BE7A1254A FOREIGN KEY (contact_id) REFERENCES contact (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA53CD04B2D FOREIGN KEY (estimate_id) REFERENCES estimate (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA5A27CB1C0 FOREIGN KEY (quote_id) REFERENCES quote (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA52989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA5609B14E6 FOREIGN KEY (rfq_invitation_id) REFERENCES rfq_invitation (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA5C59F36BE FOREIGN KEY (call_session_id) REFERENCES call_session (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA5BA227DAB FOREIGN KEY (call_recording_id) REFERENCES call_recording (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA57E707088 FOREIGN KEY (call_transcript_id) REFERENCES call_transcript (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA5F39B4E24 FOREIGN KEY (call_summary_id) REFERENCES call_summary (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE communication_timeline_item ADD CONSTRAINT FK_54A4EA5B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE communication_timeline_item');
    }
}
