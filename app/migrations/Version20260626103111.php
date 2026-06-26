<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Isola o domínio Ponto por escritório (P2.3 da remediação multi-tenant).
 *
 * Adiciona tenant_id em `registro_ponto` e `justificativa_ponto`. Ambas são user-owned e
 * independentes entre si — o backfill de cada uma vem do VÍNCULO do usuário, não uma da outra.
 *
 * Modelo POR VÍNCULO (decisão do dono): o registro/justificativa pertence ao escritório sob o
 * qual foi batido. Como o esquema antigo não guardava esse tenant, o backfill o deriva do
 * único vínculo ativo do usuário em `user_tenant`:
 *   1. coluna nullable.
 *   2. backfill SÓ quando o usuário tem exatamente 1 vínculo ativo (não-ambíguo).
 *   3. fallback determinístico para tenant único (cobre usuários sem vínculo ativo em
 *      instalação single-tenant — ex.: dev).
 *   4. SET NOT NULL + FK + índice. Se sobrar NULL (usuário com >1 vínculo ativo, ou sem
 *      vínculo em instalação multi-tenant), o NOT NULL ABORTA a migration de propósito —
 *      o caso ambíguo precisa de resolução manual antes do deploy.
 *
 * Aplicar ISOLADA via `migrations:execute --up` — o ledger não tem as 2 migrations antigas
 * do Ponto (Version20260401000000/Version20260408180237), então `migrate` é inseguro.
 */
final class Version20260626103111 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tenant_id em registro_ponto e justificativa_ponto para isolar o ponto por escritório';
    }

    public function up(Schema $schema): void
    {
        // ---- registro_ponto ----
        $this->addSql('ALTER TABLE registro_ponto ADD tenant_id INT DEFAULT NULL');

        // 1) backfill por vínculo: tenant do único vínculo ativo do usuário (não-ambíguo)
        $this->addSql(<<<'SQL'
            UPDATE registro_ponto r SET tenant_id = (
                SELECT ut.tenant_id FROM user_tenant ut
                WHERE ut.user_id = r.user_id AND ut.is_active = true
            )
            WHERE r.tenant_id IS NULL
              AND (SELECT COUNT(*) FROM user_tenant ut WHERE ut.user_id = r.user_id AND ut.is_active = true) = 1
        SQL);

        // 2) fallback determinístico para tenant único
        $this->addSql(<<<'SQL'
            UPDATE registro_ponto SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
            WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
        SQL);

        // 3) trava: NULL remanescente (ambíguo/órfão multi-tenant) -> NOT NULL aborta (rollback)
        $this->addSql('ALTER TABLE registro_ponto ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE registro_ponto ADD CONSTRAINT FK_2ED7D7529033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_2ED7D7529033212A ON registro_ponto (tenant_id)');

        // ---- justificativa_ponto ----
        $this->addSql('ALTER TABLE justificativa_ponto ADD tenant_id INT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE justificativa_ponto j SET tenant_id = (
                SELECT ut.tenant_id FROM user_tenant ut
                WHERE ut.user_id = j.user_id AND ut.is_active = true
            )
            WHERE j.tenant_id IS NULL
              AND (SELECT COUNT(*) FROM user_tenant ut WHERE ut.user_id = j.user_id AND ut.is_active = true) = 1
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE justificativa_ponto SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
            WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
        SQL);

        $this->addSql('ALTER TABLE justificativa_ponto ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE justificativa_ponto ADD CONSTRAINT FK_211D30B69033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_211D30B69033212A ON justificativa_ponto (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE justificativa_ponto DROP CONSTRAINT FK_211D30B69033212A');
        $this->addSql('DROP INDEX IDX_211D30B69033212A');
        $this->addSql('ALTER TABLE justificativa_ponto DROP tenant_id');

        $this->addSql('ALTER TABLE registro_ponto DROP CONSTRAINT FK_2ED7D7529033212A');
        $this->addSql('DROP INDEX IDX_2ED7D7529033212A');
        $this->addSql('ALTER TABLE registro_ponto DROP tenant_id');
    }
}
