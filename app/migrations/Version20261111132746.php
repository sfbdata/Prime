<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Gerada pelo make:migration; removidas as divergências preexistentes do banco DEV. */
final class Version20261111132746 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Voto: código público estável da assembleia para o QR Code do edital';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia ADD codigo_publico VARCHAR(32) DEFAULT NULL');
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM voto.assembleia ORDER BY id') as $id) {
            $this->addSql('UPDATE voto.assembleia SET codigo_publico = :codigo WHERE id = :id', [
                'codigo' => bin2hex(random_bytes(16)), 'id' => $id,
            ]);
        }
        $this->addSql('ALTER TABLE voto.assembleia ALTER COLUMN codigo_publico SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_voto_assembleia_codigo_publico ON voto.assembleia (codigo_publico)');
        $this->addSql('ALTER TABLE voto.assembleia_unidade ADD codigo_hash VARCHAR(64) DEFAULT NULL, ADD codigo_desafio_hash VARCHAR(64) DEFAULT NULL, ADD codigo_expira_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD codigo_tentativas SMALLINT DEFAULT 0 NOT NULL, ADD codigo_janela_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ADD codigo_pedidos SMALLINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE voto.assembleia_unidade DROP codigo_hash, DROP codigo_desafio_hash, DROP codigo_expira_em, DROP codigo_tentativas, DROP codigo_janela_em, DROP codigo_pedidos');
        $this->addSql('DROP INDEX voto.uniq_voto_assembleia_codigo_publico');
        $this->addSql('ALTER TABLE voto.assembleia DROP codigo_publico');
    }
}
