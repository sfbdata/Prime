<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adiciona chamado.tenant_id (isolamento multi-tenant do ServiceDesk).
 *
 * Estratégia: cria a coluna nullable, faz backfill a partir do user_tenant ativo do
 * solicitante, então aplica NOT NULL + FK + índice. Premissa: todo chamado tem solicitante
 * com um user_tenant ativo (o módulo esteve quebrado/HTTP 500 até a correção dos métodos, a
 * base de chamados reais deve ser ~vazia).
 */
final class Version20260625120342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona tenant_id em chamado para isolar o ServiceDesk por escritório';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chamado ADD tenant_id INT DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE chamado SET tenant_id = (
                SELECT ut.tenant_id FROM user_tenant ut
                WHERE ut.user_id = chamado.solicitante_id AND ut.is_active = true
                ORDER BY ut.id ASC LIMIT 1
            ) WHERE tenant_id IS NULL
        SQL);

        $this->addSql('ALTER TABLE chamado ALTER COLUMN tenant_id SET NOT NULL');
        // Nomes de FK/índice na convenção do Doctrine (batem com schema:update / schema:validate).
        $this->addSql('ALTER TABLE chamado ADD CONSTRAINT FK_3B02066F9033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_3B02066F9033212A ON chamado (tenant_id)');
    }

    public function down(Schema $schema): void
    {
        // DROP da coluna remove também a FK e o índice dependentes no PostgreSQL.
        $this->addSql('ALTER TABLE chamado DROP tenant_id');
    }
}
