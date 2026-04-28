<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260428144912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona campo criado_por_id ao tenant para rastrear o criador do escritório';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant ADD criado_por_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tenant ADD CONSTRAINT FK_TENANT_CRIADO_POR FOREIGN KEY (criado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_TENANT_CRIADO_POR ON tenant (criado_por_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant DROP CONSTRAINT FK_TENANT_CRIADO_POR');
        $this->addSql('DROP INDEX IDX_TENANT_CRIADO_POR');
        $this->addSql('ALTER TABLE tenant DROP criado_por_id');
    }
}
