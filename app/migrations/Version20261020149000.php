<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Relatório da Central de envios, F3e (spec docs/specs/comercial-marcas.md): as marcas de cada Master nas campanhas
 * (estrela, arquivada e removida do relatório). Uma linha por campanha e Master; sem linha, sem marca. Não semeia nada.
 */
final class Version20261020149000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabela comercial_campanha_marca (estrela, arquivo e remoção do Relatório, por Master)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE comercial_campanha_marca (estrela BOOLEAN DEFAULT false NOT NULL, arquivada BOOLEAN DEFAULT false NOT NULL, removida_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, alterada_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, campanha_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (campanha_id, user_id))');
        $this->addSql('CREATE INDEX IDX_BD793129310F404B ON comercial_campanha_marca (campanha_id)');
        $this->addSql('CREATE INDEX idx_comercial_campanha_marca_user ON comercial_campanha_marca (user_id)');
        $this->addSql('ALTER TABLE comercial_campanha_marca ADD CONSTRAINT FK_BD793129310F404B FOREIGN KEY (campanha_id) REFERENCES comercial_campanha (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comercial_campanha_marca ADD CONSTRAINT FK_BD793129A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE comercial_campanha_marca');
    }
}
