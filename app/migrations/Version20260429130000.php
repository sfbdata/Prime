<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomear tabelas escala_trabalho e bloco_escala_usuario para jornada_colaborador e bloco_jornada_colaborador';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bloco_escala_usuario RENAME TO bloco_jornada_colaborador');
        $this->addSql('ALTER TABLE escala_trabalho RENAME TO jornada_colaborador');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bloco_jornada_colaborador RENAME TO bloco_escala_usuario');
        $this->addSql('ALTER TABLE jornada_colaborador RENAME TO escala_trabalho');
    }
}
