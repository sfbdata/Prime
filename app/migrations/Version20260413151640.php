<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260413151640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE pasta_marcador (pasta_id INT NOT NULL, marcador_id INT NOT NULL, PRIMARY KEY (pasta_id, marcador_id))');
        $this->addSql('CREATE INDEX IDX_82D9D4B7FCDBC8C ON pasta_marcador (pasta_id)');
        $this->addSql('CREATE INDEX IDX_82D9D4BB323D722 ON pasta_marcador (marcador_id)');
        $this->addSql('ALTER TABLE pasta_marcador ADD CONSTRAINT FK_82D9D4B7FCDBC8C FOREIGN KEY (pasta_id) REFERENCES pasta (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE pasta_marcador ADD CONSTRAINT FK_82D9D4BB323D722 FOREIGN KEY (marcador_id) REFERENCES marcador (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pasta_marcador DROP CONSTRAINT FK_82D9D4B7FCDBC8C');
        $this->addSql('ALTER TABLE pasta_marcador DROP CONSTRAINT FK_82D9D4BB323D722');
        $this->addSql('DROP TABLE pasta_marcador');
    }
}
