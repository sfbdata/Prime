<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260407151749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomear status "aprovado" para "abonado" em justificativa_ponto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE justificativa_ponto SET status = 'abonado' WHERE status = 'aprovado'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE justificativa_ponto SET status = 'aprovado' WHERE status = 'abonado'");
    }
}
