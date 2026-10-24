<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Foto do cabeçalho da pasta sem cliente cadastrado (desenho 02 VS 18.10.26, L1255 e L3853).
 *
 * - `pasta.foto`: só o NOME da chave no armazenamento (categoria `pasta_foto`, escopo do escritório), como
 *   `cliente.foto`; nulo = sem foto. Com cliente cadastrado a foto que vale é a do cliente; esta aparece enquanto
 *   ele não tiver foto própria.
 * - `pasta.foto_atualizada_em`: quando a foto mudou; vai como `?v=` na URL da foto.
 *
 * Só as duas colunas: o resto do `schema:update --dump-sql` do banco da frente é ruído de outras frentes e
 * índices funcionais que o Doctrine não representa (skill criar-migration). Nada é preenchido para trás.
 */
final class Version20261020152000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Foto da pasta sem cliente: colunas foto e foto_atualizada_em em pasta';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta ADD foto VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE pasta ADD foto_atualizada_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pasta DROP foto_atualizada_em');
        $this->addSql('ALTER TABLE pasta DROP foto');
    }
}
