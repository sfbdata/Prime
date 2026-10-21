<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Equipe da conta (desenho bj-contas.js, VS 18.10.26; spec docs/specs/equipe-da-conta.md §2): situação da pessoa no
 * escritório (aprovado, em análise, suspenso). Só acréscimo: todo vínculo existente fica aprovado, e o isActive de
 * cada um não muda (os inativos de legado continuam inativos, com situação aprovado).
 */
final class Version20261020132000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Equipe da conta: user_tenant.situacao (aprovado, em_analise, suspenso)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user_tenant ADD situacao VARCHAR(20) DEFAULT 'aprovado' NOT NULL");
        $this->addSql("ALTER TABLE user_tenant ADD CONSTRAINT chk_user_tenant_situacao CHECK (situacao IN ('aprovado', 'em_analise', 'suspenso'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_tenant DROP CONSTRAINT chk_user_tenant_situacao');
        $this->addSql('ALTER TABLE user_tenant DROP situacao');
    }
}
