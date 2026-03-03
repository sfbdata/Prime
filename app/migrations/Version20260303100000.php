<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona coluna NUP na tabela cliente';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cliente ADD nup VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cliente DROP COLUMN nup');
    }
}
