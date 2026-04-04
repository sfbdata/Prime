<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260404164443 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE registro_ponto ALTER latitude DROP NOT NULL');
        $this->addSql('ALTER TABLE registro_ponto ALTER longitude DROP NOT NULL');
        $this->addSql('ALTER TABLE registro_ponto ALTER precisao_gps DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE registro_ponto ALTER latitude SET NOT NULL');
        $this->addSql('ALTER TABLE registro_ponto ALTER longitude SET NOT NULL');
        $this->addSql('ALTER TABLE registro_ponto ALTER precisao_gps SET NOT NULL');
    }
}
