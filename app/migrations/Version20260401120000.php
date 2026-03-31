<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove colunas legadas de SSID do módulo de ponto eletrônico';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registro_ponto DROP COLUMN ssid');
        $this->addSql('ALTER TABLE sede DROP COLUMN ssids_autorizados');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE registro_ponto ADD ssid VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE sede ADD ssids_autorizados JSON DEFAULT NULL');
    }
}
