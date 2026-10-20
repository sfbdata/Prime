<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agenda nova (desenho 09, entrega 3): o compromisso ganha tipo (Compromisso, Audiência, Reunião,
 * Prazo), pasta opcional, minutos do lembrete e a lista de colegas avisados no Chat. Colunas novas,
 * todas anuláveis ou com padrão; os eventos existentes não mudam (sem tipo, o calendário continua
 * saindo da legenda de cor). Spec: docs/specs/agenda-vs-18-10-26.md.
 */
final class Version20261020122500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agenda: evento.tipo, evento.pasta_id, evento.lembrete_minutos e evento.avisados';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evento ADD tipo VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE evento ADD lembrete_minutos INT DEFAULT NULL');
        $this->addSql("ALTER TABLE evento ADD avisados JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE evento ADD pasta_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE evento ADD CONSTRAINT FK_47860B057FCDBC8C FOREIGN KEY (pasta_id) REFERENCES pasta (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_47860B057FCDBC8C ON evento (pasta_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evento DROP CONSTRAINT FK_47860B057FCDBC8C');
        $this->addSql('DROP INDEX IDX_47860B057FCDBC8C');
        $this->addSql('ALTER TABLE evento DROP pasta_id');
        $this->addSql('ALTER TABLE evento DROP avisados');
        $this->addSql('ALTER TABLE evento DROP lembrete_minutos');
        $this->addSql('ALTER TABLE evento DROP tipo');
    }
}
