<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703194209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fase 4 captura total Datajud: snapshot bruto (_source) do processo em datajud_raw (JSON)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processo ADD datajud_raw JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processo DROP datajud_raw');
    }
}
