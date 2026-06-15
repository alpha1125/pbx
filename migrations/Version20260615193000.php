<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CRM phase 4 estimate, quote, and tax delivery fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant ADD quote_tax_rate_bps INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE estimate ADD exclusions TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE estimate ADD assumptions TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE estimate_line_item ADD section_label VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE quote_line_item ADD section_label VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_line_item ADD section_label VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE quote ADD parent_quote_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE quote ADD root_quote_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE quote ADD share_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE quote ADD revision_number INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE quote ADD viewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE quote ADD declined_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE quote ADD internal_review_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE quote ADD discount_cents INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE quote ADD deposit_cents INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE quote ADD financing_notes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE quote ADD accepted_by_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE quote ADD accepted_by_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE quote ADD accepted_message TEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_quote_share_token ON quote (share_token)');
        $this->addSql('CREATE INDEX idx_quote_parent_quote ON quote (parent_quote_id)');
        $this->addSql('CREATE INDEX idx_quote_root_quote ON quote (root_quote_id)');
        $this->addSql('ALTER TABLE quote ADD CONSTRAINT FK_24B1A6D772E2A30F FOREIGN KEY (parent_quote_id) REFERENCES quote (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE quote ADD CONSTRAINT FK_24B1A6D7727D70F2 FOREIGN KEY (root_quote_id) REFERENCES quote (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quote DROP CONSTRAINT FK_24B1A6D772E2A30F');
        $this->addSql('ALTER TABLE quote DROP CONSTRAINT FK_24B1A6D7727D70F2');
        $this->addSql('DROP INDEX uniq_quote_share_token');
        $this->addSql('DROP INDEX idx_quote_parent_quote');
        $this->addSql('DROP INDEX idx_quote_root_quote');
        $this->addSql('ALTER TABLE quote DROP COLUMN parent_quote_id');
        $this->addSql('ALTER TABLE quote DROP COLUMN root_quote_id');
        $this->addSql('ALTER TABLE quote DROP COLUMN share_token');
        $this->addSql('ALTER TABLE quote DROP COLUMN revision_number');
        $this->addSql('ALTER TABLE quote DROP COLUMN viewed_at');
        $this->addSql('ALTER TABLE quote DROP COLUMN declined_at');
        $this->addSql('ALTER TABLE quote DROP COLUMN internal_review_at');
        $this->addSql('ALTER TABLE quote DROP COLUMN discount_cents');
        $this->addSql('ALTER TABLE quote DROP COLUMN deposit_cents');
        $this->addSql('ALTER TABLE quote DROP COLUMN financing_notes');
        $this->addSql('ALTER TABLE quote DROP COLUMN accepted_by_name');
        $this->addSql('ALTER TABLE quote DROP COLUMN accepted_by_email');
        $this->addSql('ALTER TABLE quote DROP COLUMN accepted_message');
        $this->addSql('ALTER TABLE invoice_line_item DROP COLUMN section_label');
        $this->addSql('ALTER TABLE quote_line_item DROP COLUMN section_label');
        $this->addSql('ALTER TABLE estimate_line_item DROP COLUMN section_label');
        $this->addSql('ALTER TABLE estimate DROP COLUMN assumptions');
        $this->addSql('ALTER TABLE estimate DROP COLUMN exclusions');
        $this->addSql('ALTER TABLE tenant DROP COLUMN quote_tax_rate_bps');
    }
}
