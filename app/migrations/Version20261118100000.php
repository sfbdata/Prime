<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Acesso restrito às pastas sob responsabilidade (spec docs/specs/acesso-restrito-e-compartilhar.md, §4.1 e §5.1):
 * `tenant_role.escopo_pasta` ('todas' ou 'responsavel') e `tenant_role.rotulo` (identificação do perfil).
 *
 * Só acrescenta colunas. Todo perfil existente fica com 'todas' (o comportamento de sempre) e sem rótulo: nenhuma
 * permissão, pasta ou vínculo muda.
 */
final class Version20261118100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Perfis: escopo de pastas (acesso restrito às pastas sob responsabilidade) e rótulo do perfil.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenant_role ADD escopo_pasta VARCHAR(20) DEFAULT 'todas' NOT NULL");
        $this->addSql('ALTER TABLE tenant_role ADD rotulo VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenant_role DROP escopo_pasta');
        $this->addSql('ALTER TABLE tenant_role DROP rotulo');
    }
}
