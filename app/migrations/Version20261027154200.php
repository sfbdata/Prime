<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Push, Diário da OAB com registro manual (spec `docs/specs/push-diario-oab-manual.md`): tabela
 * `djen_diario_oab`, uma linha por publicação registrada, visível só a quem registrou. Só aditiva.
 */
final class Version20261027154200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Push: tabela djen_diario_oab (Diário da OAB manual)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE djen_diario_oab (id SERIAL NOT NULL, tenant_id INT NOT NULL, usuario_id INT NOT NULL, processo_id INT DEFAULT NULL, data_publicacao DATE NOT NULL, seccional VARCHAR(20) NOT NULL, tipo VARCHAR(20) NOT NULL, ato VARCHAR(255) NOT NULL, numero_processo VARCHAR(50) DEFAULT NULL, orgao VARCHAR(255) NOT NULL, prazo_dias SMALLINT DEFAULT NULL, texto TEXT NOT NULL, lida_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, excluida_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, criado_em TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_djen_diario_oab_usuario ON djen_diario_oab (tenant_id, usuario_id, excluida_em, data_publicacao)');
        $this->addSql('CREATE INDEX idx_djen_diario_oab_usuario_id ON djen_diario_oab (usuario_id)');
        $this->addSql('CREATE INDEX idx_djen_diario_oab_processo ON djen_diario_oab (processo_id)');
        $this->addSql('ALTER TABLE djen_diario_oab ADD CONSTRAINT fk_djen_diario_oab_tenant FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE djen_diario_oab ADD CONSTRAINT fk_djen_diario_oab_usuario FOREIGN KEY (usuario_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE djen_diario_oab ADD CONSTRAINT fk_djen_diario_oab_processo FOREIGN KEY (processo_id) REFERENCES processo (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE djen_diario_oab');
    }
}
