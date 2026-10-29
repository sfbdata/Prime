<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Padroniza o nome do perfil de sistema: "Administrador Master" vira "Administrador do Escritório", o nome que o
 * TenantBootstrapService grava e procura. Decisão do dono em 29/10. Idempotente (só toca onde o nome ainda é o antigo
 * e o perfil é de sistema). Tem UPDATE: o deploy automático para nesta migration e o dono segue à mão.
 */
final class Version20261028200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Perfil de sistema "Administrador Master" passa a se chamar "Administrador do Escritório"';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE tenant_role SET name = 'Administrador do Escritório' WHERE name = 'Administrador Master' AND is_system = TRUE");
    }

    public function down(Schema $schema): void
    {
        // Em produção só o perfil id 1 (escritório 1) tinha o nome antigo; o down devolve esse e nenhum outro.
        $this->addSql("UPDATE tenant_role SET name = 'Administrador Master' WHERE id = 1 AND name = 'Administrador do Escritório' AND is_system = TRUE");
    }
}
