<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Foto do cliente no cabeçalho da pasta (desenho 02 VS 18.10.26, L1255 e L3853).
 *
 * - `cliente.foto`: só o NOME da chave no armazenamento (categoria `cliente_foto`, escopo do escritório), como
 *   `user_profile.foto_url`; nulo = sem foto (o cabeçalho mostra o ícone).
 * - `cliente.foto_atualizada_em`: quando a foto mudou; vai como `?v=` na URL da foto.
 *
 * Só as duas colunas: o resto do `schema:update --dump-sql` do banco da frente é ruído de outras frentes e
 * índices funcionais que o Doctrine não representa (skill criar-migration). Nada é preenchido para trás.
 */
final class Version20261020151200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Foto do cliente: colunas foto e foto_atualizada_em em cliente';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cliente ADD foto VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE cliente ADD foto_atualizada_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cliente DROP foto_atualizada_em');
        $this->addSql('ALTER TABLE cliente DROP foto');
    }
}
