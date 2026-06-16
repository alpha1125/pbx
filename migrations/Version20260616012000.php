<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616012000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add RFQ vendor notification preferences to tenants.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant ADD rfq_vendor_email_notifications_enabled BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('ALTER TABLE tenant ADD rfq_vendor_sms_notifications_enabled BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP rfq_vendor_sms_notifications_enabled');
        $this->addSql('ALTER TABLE tenant DROP rfq_vendor_email_notifications_enabled');
    }
}
