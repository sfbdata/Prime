<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BlueJus Voto: link da sala virtual do debate (Meet, Zoom, Teams...) na assembleia. Uma coluna nula em
 * `voto.assembleia`: NULL = a organização não informou o link. Só o SQL desta coluna entrou (conferido contra o
 * `doctrine:schema:update --dump-sql`).
 */
final class Version20261126130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BlueJus Voto: link da sala virtual da assembleia (voto.assembleia.link_sala_virtual).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia ADD link_sala_virtual VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia DROP link_sala_virtual');
    }
}
