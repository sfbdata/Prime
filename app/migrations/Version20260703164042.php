<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703164042 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fase 1 captura total Datajud: metadados escalares do processo (sigilo, formato, sistema, codigos TPU, id do doc)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE processo ADD nivel_sigilo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD formato VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD formato_codigo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD sistema VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD sistema_codigo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD classe_codigo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD orgao_julgador_codigo VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD orgao_julgador_municipio_ibge INT DEFAULT NULL');
        $this->addSql('ALTER TABLE processo ADD datajud_id VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE processo DROP nivel_sigilo');
        $this->addSql('ALTER TABLE processo DROP formato');
        $this->addSql('ALTER TABLE processo DROP formato_codigo');
        $this->addSql('ALTER TABLE processo DROP sistema');
        $this->addSql('ALTER TABLE processo DROP sistema_codigo');
        $this->addSql('ALTER TABLE processo DROP classe_codigo');
        $this->addSql('ALTER TABLE processo DROP orgao_julgador_codigo');
        $this->addSql('ALTER TABLE processo DROP orgao_julgador_municipio_ibge');
        $this->addSql('ALTER TABLE processo DROP datajud_id');
    }
}
