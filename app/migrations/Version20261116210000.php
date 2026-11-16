<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Dados da candidatura (descrição na urna, propostas, composição da chapa e unidade do candidato). */
final class Version20261116210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona descrição, propostas, composição e unidade do candidato às opções da pauta da assembleia.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_opcao ADD descricao VARCHAR(280) DEFAULT NULL');
        $this->addSql('ALTER TABLE voto.assembleia_opcao ADD propostas TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE voto.assembleia_opcao ADD composicao TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE voto.assembleia_opcao ADD unidade_candidato VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_opcao DROP descricao');
        $this->addSql('ALTER TABLE voto.assembleia_opcao DROP propostas');
        $this->addSql('ALTER TABLE voto.assembleia_opcao DROP composicao');
        $this->addSql('ALTER TABLE voto.assembleia_opcao DROP unidade_candidato');
    }
}
