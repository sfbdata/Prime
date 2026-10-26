<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Convênio OAB por estado (spec docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P4b): tabela GLOBAL
 * `convenio_uf`, uma linha por UF cujo convênio NÃO é gratuito (valor diferenciado ou encerrado). Sem linha, o
 * convênio está ativo e grátis, então nada é semeado.
 */
final class Version20261026151800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convênio OAB por estado: tabela convenio_uf (uma linha por UF não gratuita, configurada pelo Master)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE convenio_uf (uf VARCHAR(2) NOT NULL, modo VARCHAR(3) NOT NULL, valor NUMERIC(8, 2) NOT NULL, desde DATE NOT NULL, motivo VARCHAR(200) DEFAULT NULL, alterado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, alterado_por_id INT DEFAULT NULL, PRIMARY KEY (uf))');
        $this->addSql('CREATE INDEX idx_convenio_uf_alterado_por ON convenio_uf (alterado_por_id)');
        $this->addSql('ALTER TABLE convenio_uf ADD CONSTRAINT FK_CONVENIO_UF_USER FOREIGN KEY (alterado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql("ALTER TABLE convenio_uf ADD CONSTRAINT chk_convenio_uf_modo CHECK (modo IN ('dif', 'sem'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE convenio_uf');
    }
}
