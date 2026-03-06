<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260305100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create legenda_cor table for customizable event color legends';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE legenda_cor (
            id SERIAL PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            cor VARCHAR(7) NOT NULL,
            ordem INT DEFAULT 0 NOT NULL,
            criado_at TIMESTAMP NOT NULL,
            modificado_em TIMESTAMP DEFAULT NULL
        )');

        // Insert default legends
        $this->addSql("INSERT INTO legenda_cor (nome, cor, ordem, criado_at) VALUES 
            ('Audiência', '#0073b7', 1, NOW()),
            ('Prazo', '#00a65a', 2, NOW()),
            ('Reunião', '#f39c12', 3, NOW()),
            ('Urgente', '#f56954', 4, NOW()),
            ('Pessoal', '#605ca8', 5, NOW()),
            ('Lembrete', '#00c0ef', 6, NOW())
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE legenda_cor');
    }
}
