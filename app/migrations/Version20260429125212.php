<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260429125212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jornada_tenant ADD validacao_repouso_habilitada BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE jornada_tenant ADD minimo_minutos_repouso INT NOT NULL DEFAULT 60');
        $this->addSql('ALTER TABLE jornada_tenant ADD validacao_interjornada_habilitada BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE jornada_tenant ADD minimo_minutos_interjornada INT NOT NULL DEFAULT 660');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE jornada_tenant DROP validacao_repouso_habilitada');
        $this->addSql('ALTER TABLE jornada_tenant DROP minimo_minutos_repouso');
        $this->addSql('ALTER TABLE jornada_tenant DROP validacao_interjornada_habilitada');
        $this->addSql('ALTER TABLE jornada_tenant DROP minimo_minutos_interjornada');
    }
}
