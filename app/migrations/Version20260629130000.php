<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Isola as solicitações de acesso por escritório (M2 da correção pós-remediação multi-tenant).
 *
 * Adiciona tenant_id em `access_request`, que passa a ser TenantAware. Sem isso, uma solicitação
 * de um usuário vinculado a dois escritórios (A e B) aparecia no painel de aprovação de AMBOS, e
 * o admin do escritório errado podia aprovar/negar (concessão sem autoridade sobre o recurso).
 *
 * Backfill (a solicitação pertence ao escritório onde foi feita; o esquema antigo não guardava
 * esse tenant, então deriva do vínculo do solicitante):
 *   1. coluna nullable.
 *   2. backfill SÓ quando o solicitante tem exatamente 1 vínculo ativo (não-ambíguo).
 *   3. fallback determinístico para tenant único (prod = 1 escritório → cobre tudo).
 *   4. SET NOT NULL + FK + índice. Se sobrar NULL (solicitante com >1 vínculo ativo, ou sem
 *      vínculo em instalação multi-tenant), o NOT NULL ABORTA de propósito — caso ambíguo
 *      precisa de resolução manual antes do deploy.
 *
 * Dev tem 0 solicitações → backfill trivial. NÃO mexe no índice parcial `uniq_access_request_pending`
 * (que, registrado no ledger, hoje não existe no banco — drift pré-existente, fora do escopo do M2).
 *
 * Aplicar ISOLADA via `migrations:execute --up` — o ledger não tem as 2 migrations antigas do
 * Ponto (Version20260401000000/Version20260408180237), então `migrate` puro é inseguro.
 */
final class Version20260629130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tenant_id em access_request para isolar o painel de aprovação por escritório';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request ADD tenant_id INT DEFAULT NULL');

        // 1) backfill por vínculo: tenant do único vínculo ativo do solicitante (não-ambíguo)
        $this->addSql(<<<'SQL'
            UPDATE access_request ar SET tenant_id = (
                SELECT ut.tenant_id FROM user_tenant ut
                WHERE ut.user_id = ar.user_id AND ut.is_active = true
            )
            WHERE ar.tenant_id IS NULL
              AND (SELECT COUNT(*) FROM user_tenant ut WHERE ut.user_id = ar.user_id AND ut.is_active = true) = 1
        SQL);

        // 2) fallback determinístico para tenant único
        $this->addSql(<<<'SQL'
            UPDATE access_request SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
            WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
        SQL);

        // 3) trava: NULL remanescente (ambíguo/órfão multi-tenant) -> NOT NULL aborta (rollback)
        $this->addSql('ALTER TABLE access_request ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE access_request ADD CONSTRAINT FK_F3B2558A9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F3B2558A9033212A ON access_request (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE access_request DROP CONSTRAINT FK_F3B2558A9033212A');
        $this->addSql('DROP INDEX IDX_F3B2558A9033212A');
        $this->addSql('ALTER TABLE access_request DROP tenant_id');
    }
}
