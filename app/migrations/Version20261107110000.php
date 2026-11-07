<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jornada com data de início, PR 2 (spec `docs/specs/ponto-jornada-vigencia.md` §10.1): `cobre_dias_anteriores` diz
 * se a versão mais antiga vale também para os dias de antes da sua data.
 *
 * **Não muda saldo:** todas as linhas existentes ficam `true`, que é exatamente a regra que a folha já aplica a elas
 * desde a `Version20261107100000`. Só as versões criadas pela tela daqui em diante nascem `false`, para a primeira
 * jornada de alguém valer só da data dela em diante.
 */
final class Version20261107110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jornada com data de início: cobre_dias_anteriores em jornada_colaborador, true nas linhas existentes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jornada_colaborador ADD cobre_dias_anteriores BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('UPDATE jornada_colaborador SET cobre_dias_anteriores = true');
    }

    public function down(Schema $schema): void
    {
        // Um `up` depois marcaria todas como cobrindo os dias anteriores, inclusive as criadas pela tela: o saldo mudaria.
        $this->abortIf(
            (bool) $this->connection->fetchOne('SELECT 1 FROM jornada_colaborador WHERE cobre_dias_anteriores = false LIMIT 1'),
            'Há jornadas criadas pela tela que valem só da data delas em diante; voltar mudaria o saldo de quem as tem.',
        );
        $this->addSql('ALTER TABLE jornada_colaborador DROP cobre_dias_anteriores');
    }
}
