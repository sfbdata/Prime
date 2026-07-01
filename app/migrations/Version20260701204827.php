<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260701204827 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona status de verificacao de OAB ao cadastro_pendente (carrega Iniciar->Confirmar)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cadastro_pendente ADD oab_status VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE cadastro_pendente ADD oab_nome_oficial VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cadastro_pendente DROP oab_status');
        $this->addSql('ALTER TABLE cadastro_pendente DROP oab_nome_oficial');
    }
}
