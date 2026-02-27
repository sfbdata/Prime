<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260227110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela audit_log para trilha de auditoria de entidades';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (id SERIAL NOT NULL, action VARCHAR(10) NOT NULL, entity_class VARCHAR(255) NOT NULL, entity_id VARCHAR(64) DEFAULT NULL, changes JSON DEFAULT NULL, actor_user_id INT DEFAULT NULL, actor_email VARCHAR(180) DEFAULT NULL, tenant_id INT DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, route VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_audit_entity ON audit_log (entity_class, entity_id)');
        $this->addSql('CREATE INDEX idx_audit_created_at ON audit_log (created_at)');
        $this->addSql('CREATE INDEX idx_audit_actor_user_id ON audit_log (actor_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_log');
    }
}