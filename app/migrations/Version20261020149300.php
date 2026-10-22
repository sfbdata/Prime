<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prioridade própria da meta (desenho 04 de Minhas Metas; spec `docs/specs/minhas-metas-abas.md`): coluna
 * `tarefa.prioridade` (normal, alta, urgente), com `normal` para toda meta existente. Só estrutura: a herança
 * da prioridade da pasta nas metas antigas é do comando `app:tarefa:herdar-prioridade-da-pasta`, rodado uma
 * vez depois da publicação (DEPLOY.md).
 */
final class Version20261020149300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Minhas Metas: prioridade própria da meta (tarefa.prioridade)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tarefa ADD prioridade VARCHAR(20) DEFAULT 'normal' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tarefa DROP prioridade');
    }
}
