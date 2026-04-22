<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422174712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE justificativa_ponto ADD tipo VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE justificativa_ponto ADD abono_parcial BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE justificativa_ponto ADD hora_inicio_abono TIME(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE justificativa_ponto ADD hora_fim_abono TIME(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE justificativa_ponto DROP tipo');
        $this->addSql('ALTER TABLE justificativa_ponto DROP abono_parcial');
        $this->addSql('ALTER TABLE justificativa_ponto DROP hora_inicio_abono');
        $this->addSql('ALTER TABLE justificativa_ponto DROP hora_fim_abono');
    }
}
