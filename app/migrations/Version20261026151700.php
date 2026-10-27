<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Metas de divulgação do Master (spec docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P3): tabela GLOBAL
 * `meta_de_divulgacao`, linha única (id 1) com o mercado, o alvo, a atualização automática e as metas e destaques por
 * estado e cidade. Sem linha valem os padrões da entidade, então nada é semeado. O CHECK garante a linha única.
 */
final class Version20261026151700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Metas de divulgação: tabela meta_de_divulgacao (linha única, configurada pelo Master)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE meta_de_divulgacao (id INT NOT NULL, mercado INT DEFAULT 1510000 NOT NULL, alvo INT DEFAULT 1000 NOT NULL, fonte VARCHAR(160) NOT NULL, referencia VARCHAR(7) NOT NULL, automatica BOOLEAN DEFAULT true NOT NULL, frequencia VARCHAR(12) DEFAULT 'mensal' NOT NULL, avancar_de_fase BOOLEAN DEFAULT true NOT NULL, mercado_manual BOOLEAN DEFAULT false NOT NULL, ultima_automatica TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, destaques JSON NOT NULL, metas_por_uf JSON NOT NULL, metas_por_cidade JSON NOT NULL, alterado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, alterado_por_id INT DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT chk_meta_de_divulgacao_linha_unica CHECK (id = 1))");
        $this->addSql('CREATE INDEX idx_meta_de_divulgacao_alterado_por ON meta_de_divulgacao (alterado_por_id)');
        $this->addSql('ALTER TABLE meta_de_divulgacao ADD CONSTRAINT FK_META_DE_DIVULGACAO_USER FOREIGN KEY (alterado_por_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE meta_de_divulgacao');
    }
}
