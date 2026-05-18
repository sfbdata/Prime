<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260518150335 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE jornada_colaborador ALTER alerta_habilitado DROP DEFAULT');
        $this->addSql('ALTER TABLE justificativa_ponto ADD tipo_registro_esquecido VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE justificativa_ponto ADD hora_registro_esquecido TIME(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE jornada_colaborador ALTER alerta_habilitado SET DEFAULT true');
        $this->addSql('ALTER TABLE justificativa_ponto DROP tipo_registro_esquecido');
        $this->addSql('ALTER TABLE justificativa_ponto DROP hora_registro_esquecido');
    }
}
