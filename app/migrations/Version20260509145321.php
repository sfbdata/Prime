<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260509145321 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona reenvio_count em invitation para limitar reenvios a 3';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation ADD reenvio_count SMALLINT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation DROP COLUMN reenvio_count');
    }
}
