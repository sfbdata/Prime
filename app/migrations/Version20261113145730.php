<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Contadores agregados, sem guardar números digitados nem associar escolhas às participações. */
final class Version20261113145730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona quantidade e peso de votos nulos por pauta';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_pauta ADD nulos_unidades INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE voto.assembleia_pauta ADD nulos_peso NUMERIC(14, 6) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_pauta DROP nulos_unidades');
        $this->addSql('ALTER TABLE voto.assembleia_pauta DROP nulos_peso');
    }
}
