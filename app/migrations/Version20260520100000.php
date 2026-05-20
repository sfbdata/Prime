<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adicionar permissões modules.expediente.view e modules.kanban.view ao catálogo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO permission (code, description, "group") VALUES (:code, :description, :group)'
            . ' ON CONFLICT (code) DO UPDATE SET description = EXCLUDED.description, "group" = EXCLUDED."group"',
            ['code' => 'modules.expediente.view', 'description' => 'Acesso ao módulo Expediente', 'group' => 'modules']
        );

        $this->addSql(
            'INSERT INTO permission (code, description, "group") VALUES (:code, :description, :group)'
            . ' ON CONFLICT (code) DO UPDATE SET description = EXCLUDED.description, "group" = EXCLUDED."group"',
            ['code' => 'modules.kanban.view', 'description' => 'Acesso ao módulo Kanban', 'group' => 'modules']
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM permission WHERE code IN ('modules.expediente.view', 'modules.kanban.view')");
    }
}
