<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BlueJus Voto: edital de convocação (PDF) anexado à assembleia. Cinco colunas nulas em `voto.assembleia`: a chave do
 * arquivo (nome cunhado pelo storage), o nome que o gestor enviou, o tamanho, o sha256 e quando foi enviado.
 *
 * Gerada pelo `doctrine:schema:update --dump-sql` e limpa: só as colunas do edital entraram.
 */
final class Version20261125160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BlueJus Voto: edital em PDF da assembleia (voto.assembleia.edital_*).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia ADD edital_chave VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE voto.assembleia ADD edital_nome VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE voto.assembleia ADD edital_tamanho INT DEFAULT NULL');
        $this->addSql('ALTER TABLE voto.assembleia ADD edital_sha256 CHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE voto.assembleia ADD edital_enviado_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia DROP edital_chave');
        $this->addSql('ALTER TABLE voto.assembleia DROP edital_nome');
        $this->addSql('ALTER TABLE voto.assembleia DROP edital_tamanho');
        $this->addSql('ALTER TABLE voto.assembleia DROP edital_sha256');
        $this->addSql('ALTER TABLE voto.assembleia DROP edital_enviado_em');
    }
}
