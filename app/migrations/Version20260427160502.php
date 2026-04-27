<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260427160502 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomear coluna status para situacao em pasta e adicionar coluna prioridade';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pasta ADD prioridade VARCHAR(255) DEFAULT \'normal\' NOT NULL');
        $this->addSql('ALTER TABLE pasta RENAME COLUMN status TO situacao');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pasta DROP prioridade');
        $this->addSql('ALTER TABLE pasta RENAME COLUMN situacao TO status');
    }
}
