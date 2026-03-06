<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration para adicionar campo responsavel_id na tabela contrato
 */
final class Version20260306160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona campo responsavel_id (FK para user) na tabela contrato';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contrato ADD COLUMN responsavel_id INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE contrato ADD CONSTRAINT FK_3D3AE85853C59D72 FOREIGN KEY (responsavel_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_3D3AE85853C59D72 ON contrato (responsavel_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contrato DROP CONSTRAINT FK_3D3AE85853C59D72');
        $this->addSql('DROP INDEX IDX_3D3AE85853C59D72');
        $this->addSql('ALTER TABLE contrato DROP COLUMN responsavel_id');
    }
}
