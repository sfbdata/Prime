<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260516120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Etapa 6.E — DROP das 8 colunas legadas de user e user_profiles';
    }

    public function up(Schema $schema): void
    {
        // BLOCO 1 — Sync DEV: corrige divergências antes do DROP.
        // No-op em produção: user_tenant é criado pela Etapa 2 no mesmo deploy,
        // sem janela de edição intermediária entre as migrations.

        // 1a. Sync codigo_funcionario: user → user_tenant (onde user_tenant está NULL)
        $this->addSql(
            'UPDATE user_tenant ut
             SET codigo_funcionario = u.codigo_funcionario
             FROM "user" u
             WHERE ut.user_id = u.id
               AND ut.tenant_id = u.tenant_id
               AND u.codigo_funcionario IS NOT NULL
               AND ut.codigo_funcionario IS NULL'
        );

        // 1b. Sync demitido_em + is_active: user → user_tenant (onde user_tenant está NULL)
        $this->addSql(
            'UPDATE user_tenant ut
             SET demitido_em = u.demitido_em,
                 is_active = false
             FROM "user" u
             WHERE ut.user_id = u.id
               AND ut.tenant_id = u.tenant_id
               AND u.demitido_em IS NOT NULL
               AND ut.demitido_em IS NULL'
        );

        // 1c. Sync data_admissao: user_profiles → user_tenant (divergente ou NULL).
        // Cobre user com data editada via form legado pós-Etapa 2 (antes de 6.B).
        $this->addSql(
            'UPDATE user_tenant ut
             SET data_admissao = up.data_admissao
             FROM user_profiles up
             JOIN "user" u ON u.id = up.user_id
             WHERE ut.user_id = up.user_id
               AND ut.tenant_id = u.tenant_id
               AND up.data_admissao IS NOT NULL
               AND (ut.data_admissao IS NULL
                    OR ut.data_admissao != up.data_admissao)'
        );

        // BLOCO 2 — Drop FKs
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT fk_8d93d6499033212a');
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT fk_8d93d649c4956098');
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT fk_8d93d649813ac380');
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT fk_8d93d6497e27c2b8');

        // BLOCO 3 — Drop indexes
        $this->addSql('DROP INDEX idx_8d93d6499033212a');
        $this->addSql('DROP INDEX idx_8d93d649c4956098');
        $this->addSql('DROP INDEX idx_8d93d649813ac380');
        $this->addSql('DROP INDEX idx_8d93d6497e27c2b8');

        // BLOCO 4 — Drop columns
        $this->addSql('ALTER TABLE "user" DROP COLUMN tenant_id');
        $this->addSql('ALTER TABLE "user" DROP COLUMN tenant_role_id');
        $this->addSql('ALTER TABLE "user" DROP COLUMN cargo_id');
        $this->addSql('ALTER TABLE "user" DROP COLUMN lotacao_id');
        $this->addSql('ALTER TABLE "user" DROP COLUMN codigo_funcionario');
        $this->addSql('ALTER TABLE "user" DROP COLUMN demitido_em');
        $this->addSql('ALTER TABLE "user" DROP COLUMN last_login');
        $this->addSql('ALTER TABLE user_profiles DROP COLUMN data_admissao');
    }

    public function down(Schema $schema): void
    {
        // Recria schema apenas — sem repopulação de dados.
        // Rollback real em produção = restore de backup.

        $this->addSql('ALTER TABLE "user" ADD tenant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD tenant_role_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD cargo_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD lotacao_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD codigo_funcionario VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD demitido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD last_login TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profiles ADD data_admissao DATE DEFAULT NULL');

        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT fk_8d93d6499033212a FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT fk_8d93d649c4956098 FOREIGN KEY (tenant_role_id) REFERENCES tenant_role (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT fk_8d93d649813ac380 FOREIGN KEY (cargo_id) REFERENCES cargo (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT fk_8d93d6497e27c2b8 FOREIGN KEY (lotacao_id) REFERENCES lotacao (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE INDEX idx_8d93d6499033212a ON "user" (tenant_id)');
        $this->addSql('CREATE INDEX idx_8d93d649c4956098 ON "user" (tenant_role_id)');
        $this->addSql('CREATE INDEX idx_8d93d649813ac380 ON "user" (cargo_id)');
        $this->addSql('CREATE INDEX idx_8d93d6497e27c2b8 ON "user" (lotacao_id)');
    }
}
