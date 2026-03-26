<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260326130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona campo visibilidade na tabela evento (todos | somente_eu)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE evento ADD visibilidade VARCHAR(20) NOT NULL DEFAULT 'todos'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evento DROP visibilidade');
    }
}
