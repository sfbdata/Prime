<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remover permissão órfã modules.precadastros.view (módulo removido em Version20260519100000)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM tenant_role_permission"
            . " WHERE permission_id = (SELECT id FROM permission WHERE code = 'modules.precadastros.view')"
        );

        $this->addSql(
            "DELETE FROM permission WHERE code = 'modules.precadastros.view'"
        );
    }

    public function down(Schema $schema): void
    {
        // Remoção intencional de catálogo órfão — não reversível.
        // O módulo PreCadastro foi removido em Version20260519100000 e não será restaurado.
    }
}
