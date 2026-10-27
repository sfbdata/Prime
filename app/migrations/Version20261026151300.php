<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Configurações globais do Master (spec docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P2): tabela GLOBAL
 * `configuracao_global`, linha única (id 1) com os valores em JSON. Sem linha valem os padrões do catálogo, então
 * nada é semeado. O CHECK garante a linha única no banco, como em tema_config.
 */
final class Version20261026151300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configurações globais: tabela configuracao_global (linha única, configurada pelo Master)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE configuracao_global (id INT NOT NULL, valores JSON NOT NULL, alterado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, alterado_por_id INT DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT chk_configuracao_global_linha_unica CHECK (id = 1))');
        $this->addSql('CREATE INDEX idx_configuracao_global_alterado_por ON configuracao_global (alterado_por_id)');
        $this->addSql('ALTER TABLE configuracao_global ADD CONSTRAINT FK_CONFIGURACAO_GLOBAL_USER FOREIGN KEY (alterado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE configuracao_global');
    }
}
