<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260616010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add RFQ vendor eligibility and service-area settings to tenants.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant ADD rfq_vendor_enabled BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("ALTER TABLE tenant ADD rfq_service_area_countries JSON DEFAULT '[]'::json NOT NULL");
        $this->addSql("ALTER TABLE tenant ADD rfq_service_area_provinces JSON DEFAULT '[]'::json NOT NULL");
        $this->addSql("ALTER TABLE tenant ADD rfq_service_area_cities JSON DEFAULT '[]'::json NOT NULL");
        $this->addSql("ALTER TABLE tenant ADD rfq_service_area_postal_prefixes JSON DEFAULT '[]'::json NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP rfq_service_area_postal_prefixes');
        $this->addSql('ALTER TABLE tenant DROP rfq_service_area_cities');
        $this->addSql('ALTER TABLE tenant DROP rfq_service_area_provinces');
        $this->addSql('ALTER TABLE tenant DROP rfq_service_area_countries');
        $this->addSql('ALTER TABLE tenant DROP rfq_vendor_enabled');
    }
}
