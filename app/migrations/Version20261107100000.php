<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jornada com data de início (spec `docs/specs/ponto-jornada-vigencia.md` §4): `jornada_colaborador` passa a guardar
 * versões, cada uma com `vale_a_partir_de`. O único deixa de ser (pessoa, escritório) e passa a ser
 * (pessoa, escritório, data).
 *
 * **Não muda saldo:** cada jornada existente vira UMA versão, e a versão mais antiga vale também para os dias
 * anteriores à sua data (`HistoricoDeJornadas`). A data é só o rótulo do início: o primeiro registro de ponto da
 * pessoa naquele escritório; sem registro, o dia em que a jornada foi criada (o `create` do `audit_log`, que existe
 * desde 22/04/2026); sem os dois, o dia da migration.
 *
 * **down:** recusa quando alguma pessoa já tem mais de uma versão no mesmo escritório, para não apagar histórico em
 * silêncio.
 */
final class Version20261107100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jornada com data de início: vale_a_partir_de em jornada_colaborador e único por pessoa, escritório e data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jornada_colaborador ADD vale_a_partir_de DATE DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE jornada_colaborador j SET vale_a_partir_de = COALESCE(
                (SELECT MIN(r.data_hora)::date FROM registro_ponto r WHERE r.user_id = j.user_id AND r.tenant_id = j.tenant_id),
                (SELECT MIN(a.created_at)::date FROM audit_log a
                  WHERE a.action = 'create'
                    AND a.entity_class IN ('App\Entity\Ponto\JornadaColaborador', 'App\Ponto\Entity\JornadaColaborador')
                    AND (a.tenant_id IS NULL OR a.tenant_id = j.tenant_id)
                    AND (a.changes -> 'diff' -> 'after' -> 'user' ->> 'id') = j.user_id::text),
                CURRENT_DATE
            )
            SQL);
        $this->addSql('ALTER TABLE jornada_colaborador ALTER vale_a_partir_de SET NOT NULL');
        $this->addSql('DROP INDEX uq_jornada_colaborador_user_tenant');
        $this->addSql('CREATE UNIQUE INDEX uq_jornada_colaborador_vigencia ON jornada_colaborador (user_id, tenant_id, vale_a_partir_de)');
    }

    public function down(Schema $schema): void
    {
        $comVersoes = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT 1 FROM jornada_colaborador GROUP BY user_id, tenant_id HAVING COUNT(*) > 1) v'
        );
        $this->abortIf(
            $comVersoes > 0,
            sprintf('%d pessoa(s) já têm mais de uma versão da jornada no mesmo escritório: voltar apagaria o histórico.', $comVersoes),
        );

        $this->addSql('DROP INDEX uq_jornada_colaborador_vigencia');
        $this->addSql('CREATE UNIQUE INDEX uq_jornada_colaborador_user_tenant ON jornada_colaborador (user_id, tenant_id)');
        $this->addSql('ALTER TABLE jornada_colaborador DROP vale_a_partir_de');
    }
}
