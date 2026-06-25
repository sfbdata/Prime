<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Isola o domínio Tarefa por escritório (P2 da remediação multi-tenant).
 *
 * Adiciona tenant_id em `tarefa` (raiz) e `tarefa_mensagem` (filha). Estratégia,
 * espelhando Processo (Version20260625192651):
 *   1. tarefa PRIMEIRO: coluna nullable -> backfill via pasta (tarefa.pasta_id é NOT NULL
 *      e toda pasta tem tenant -> 0 órfãos determinístico) -> fallback de tenant único
 *      (simetria/defesa) -> SET NOT NULL + FK + índice. Se sobrar órfão em ambiente
 *      multi-tenant, o NOT NULL aborta a migration (rollback).
 *   2. tarefa_mensagem herda o tenant da tarefa pai (FK tarefa_id NOT NULL, 0 órfãs
 *      estruturais). Recebe coluna própria (não só herança transitiva) porque há rotas
 *      que a carregam por id direto (editar/visualizar anexo da mensagem) — o filtro só
 *      fecha o IDOR por id de entidade TenantAware.
 *
 * Ordem importa: tarefa_mensagem só é preenchida depois de tarefa.tenant_id estar populado.
 */
final class Version20260625212332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tenant_id em tarefa e tarefa_mensagem para isolar as tarefas por escritório';
    }

    public function up(Schema $schema): void
    {
        // ---- tarefa (raiz) ----
        $this->addSql('ALTER TABLE tarefa ADD tenant_id INT DEFAULT NULL');

        // 1) backfill natural: tenant da pasta dona da tarefa (pasta_id é NOT NULL)
        $this->addSql('UPDATE tarefa t SET tenant_id = p.tenant_id FROM pasta p WHERE p.id = t.pasta_id AND t.tenant_id IS NULL');

        // 2) fallback determinístico para órfãos SÓ quando existe um único tenant
        $this->addSql(<<<'SQL'
            UPDATE tarefa SET tenant_id = (SELECT id FROM tenant ORDER BY id LIMIT 1)
            WHERE tenant_id IS NULL AND (SELECT COUNT(*) FROM tenant) = 1
        SQL);

        // 3) trava: órfão remanescente (multi-tenant) -> NOT NULL aborta tudo (rollback)
        $this->addSql('ALTER TABLE tarefa ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE tarefa ADD CONSTRAINT FK_31B4CBA9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_31B4CBA9033212A ON tarefa (tenant_id)');

        // ---- tarefa_mensagem (herda da tarefa pai) ----
        $this->addSql('ALTER TABLE tarefa_mensagem ADD tenant_id INT DEFAULT NULL');
        $this->addSql('UPDATE tarefa_mensagem m SET tenant_id = t.tenant_id FROM tarefa t WHERE t.id = m.tarefa_id AND m.tenant_id IS NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ALTER COLUMN tenant_id SET NOT NULL');
        $this->addSql('ALTER TABLE tarefa_mensagem ADD CONSTRAINT FK_F0B050EB9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_F0B050EB9033212A ON tarefa_mensagem (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarefa_mensagem DROP CONSTRAINT FK_F0B050EB9033212A');
        $this->addSql('DROP INDEX IDX_F0B050EB9033212A');
        $this->addSql('ALTER TABLE tarefa_mensagem DROP tenant_id');

        $this->addSql('ALTER TABLE tarefa DROP CONSTRAINT FK_31B4CBA9033212A');
        $this->addSql('DROP INDEX IDX_31B4CBA9033212A');
        $this->addSql('ALTER TABLE tarefa DROP tenant_id');
    }
}
