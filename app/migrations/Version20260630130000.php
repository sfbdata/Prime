<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * B4 — índice em `audit_log (tenant_id, created_at)`.
 *
 * `audit_log` era a única tabela com `tenant_id` sem índice. As queries de timeline/auditoria
 * sempre filtram `tenant_id` (e ordenam por `created_at`), então o índice composto cobre o padrão
 * de acesso e evita seq scan conforme a tabela cresce. Apenas performance — sem mudança de dado e
 * sem efeito de isolamento (o filtro manual de `tenant_id` já existe em todos os caminhos).
 */
final class Version20260630130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria índice (tenant_id, created_at) em audit_log (B4 — performance)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_audit_tenant_created ON audit_log (tenant_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_tenant_created');
    }
}
