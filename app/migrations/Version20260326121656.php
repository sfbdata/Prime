<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260326121656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pasta ADD responsavel_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta ADD CONSTRAINT FK_9B3BBC81BB9AF004 FOREIGN KEY (responsavel_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9B3BBC81BB9AF004 ON pasta (responsavel_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pasta DROP CONSTRAINT FK_9B3BBC81BB9AF004');
        $this->addSql('DROP INDEX IDX_9B3BBC81BB9AF004');
        $this->addSql('ALTER TABLE pasta DROP responsavel_id');
    }
}
