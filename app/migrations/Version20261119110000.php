<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Opções avançadas" do Meu Perfil (spec docs/specs/sistema-em-ingles.md, §2): fuso horário, formato de data e de
 * números, três colunas nulas em `user_profiles` (nulo = Brasília; automático, pelo idioma). Sem backfill.
 */
final class Version20261119110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meu Perfil: opções avançadas (user_profiles.fuso_horario, formato_data, formato_numero)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profiles ADD fuso_horario VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profiles ADD formato_data VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_profiles ADD formato_numero VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profiles DROP fuso_horario');
        $this->addSql('ALTER TABLE user_profiles DROP formato_data');
        $this->addSql('ALTER TABLE user_profiles DROP formato_numero');
    }
}
