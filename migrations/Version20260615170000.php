<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add invitation status fields to user tenant memberships';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_tenant_membership ADD status VARCHAR(20) DEFAULT 'active' NOT NULL");
        $this->addSql('ALTER TABLE user_tenant_membership ADD invite_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_tenant_membership ADD invited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE user_tenant_membership ADD accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_753E4FBF3B62A5E0 ON user_tenant_membership (invite_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_753E4FBF3B62A5E0');
        $this->addSql('ALTER TABLE user_tenant_membership DROP status');
        $this->addSql('ALTER TABLE user_tenant_membership DROP invite_token');
        $this->addSql('ALTER TABLE user_tenant_membership DROP invited_at');
        $this->addSql('ALTER TABLE user_tenant_membership DROP accepted_at');
    }
}
