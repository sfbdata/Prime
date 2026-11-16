<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Foto 3x4 opcional da candidatura: guarda só a chave do arquivo no armazenamento do sistema. */
final class Version20261116230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona a foto opcional (chave do arquivo) à opção da pauta do Voto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_opcao ADD foto VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_opcao DROP foto');
    }
}
