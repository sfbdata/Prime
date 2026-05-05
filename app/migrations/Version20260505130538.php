<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505130538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove coluna descricao da tabela justificativa_ponto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE justificativa_ponto DROP COLUMN descricao');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE justificativa_ponto ADD COLUMN descricao TEXT NOT NULL DEFAULT \'\'');
    }
}
