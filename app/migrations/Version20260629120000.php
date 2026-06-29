<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Isola as notificações por escritório (M4 da correção pós-remediação multi-tenant).
 *
 * Adiciona tenant_id em `notificacao`, que passa a ser TenantAware. Sem isso, um usuário
 * vinculado a dois escritórios (A e B) logado em B via no sino as notificações geradas em A
 * (título/URL de tarefa, evento, chamado e ponto de outro escritório).
 *
 * Backfill:
 *   1. coluna nullable.
 *   2. notificações DE TAREFA derivam o tenant da própria tarefa (tarefa.tenant_id) — é o
 *      único vínculo direto disponível; o FK notificacao→tarefa é ON DELETE CASCADE, então
 *      toda notificação com tarefa_id tem uma tarefa viva.
 *   3. o restante (evento/chamado/ponto/sem origem) cai no fallback determinístico de tenant
 *      único — em produção há 1 escritório, então cobre tudo.
 *   4. SET NOT NULL + FK + índice. Se sobrar NULL (instalação multi-tenant com notificação não
 *      derivável), o NOT NULL ABORTA a migration de propósito: o caso ambíguo precisa de
 *      resolução manual antes do deploy.
 *
 * Aplicar ISOLADA via `migrations:execute --up` — o ledger não tem as 2 migrations antigas do
 * Ponto (Version20260401000000/Version20260408180237), então `migrate` puro é inseguro.
 */
final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tenant_id em notificacao para isolar o sino de notificações por escritório';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notificacao ADD tenant_id INT DEFAULT NULL');

        // 1) notificações de tarefa: tenant da tarefa (único vínculo direto)
        $this->addSql(<<<'SQL'
            UPDATE notificacao n SET tenant_id = (
                SELECT t.tenant_id FROM tarefa t WHERE t.id = n.tarefa_id
            )
            WHERE n.tenant_id IS NULL AND n.tarefa_id IS NOT NULL
        SQL);

        // 2) fallback determinístico para tenant único (evento/chamado/ponto/sem origem)
        $this->addSql(<<<'SQL'
            UPDATE notificacao SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
            WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
        SQL);

        // 3) trava: NULL remanescente (não-derivável em multi-tenant) -> NOT NULL aborta (rollback)
        $this->addSql('ALTER TABLE notificacao ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE notificacao ADD CONSTRAINT FK_5ACD93869033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5ACD93869033212A ON notificacao (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notificacao DROP CONSTRAINT FK_5ACD93869033212A');
        $this->addSql('DROP INDEX IDX_5ACD93869033212A');
        $this->addSql('ALTER TABLE notificacao DROP tenant_id');
    }
}
