<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Jornada e ponto por escritório (spec `docs/specs/seguranca-b-jornada.md`, achado de auditoria interna F8/F11).
 *
 * - `jornada_colaborador.tenant_id`: entra nulo, recebe o backfill da regra 4 e vira NOT NULL. O índice único passa de
 *   `user_id` a `(user_id, tenant_id)`.
 *   - um escritório ativo: a jornada vai para ele;
 *   - vários ativos: vai para o da última batida entre eles; sem batida, para o vínculo mais antigo (contado no log);
 *   - sem vínculo ativo: vai para o da última batida (remover o colaborador apaga o `user_tenant`, a batida fica); sem
 *     batida nenhuma, a jornada órfã é apagada (contada no log).
 *   "Ativo" é o vínculo ativo num escritório ativo, como na trava `EntradaAbertaEmOutroEscritorio`.
 * - `user_tenant.registra_ponto`: ligada só para quem tem batida naquele escritório nos últimos 30 dias (regra 5). O
 *   padrão da coluna fica `true` depois do backfill: toda entrada nova nasce ligada.
 *
 * Os SQL do backfill são constantes públicas para o teste de integração (`BackfillDaJornadaPorEscritorioTest`).
 */
final class Version20261020150700 extends AbstractMigration
{
    /** O escritório de destino de cada jornada ainda sem escritório (regra 4). */
    public const SQL_DESTINO_DA_JORNADA = <<<'SQL'
        WITH ativos AS (
            SELECT ut.id, ut.user_id, ut.tenant_id, ut.created_at
              FROM user_tenant ut
              JOIN tenant t ON t.id = ut.tenant_id
             WHERE ut.is_active AND t.is_active
        ),
        ultima_batida_entre_ativos AS (
            SELECT DISTINCT ON (rp.user_id) rp.user_id, rp.tenant_id
              FROM registro_ponto rp
              JOIN ativos a ON a.user_id = rp.user_id AND a.tenant_id = rp.tenant_id
             ORDER BY rp.user_id, rp.data_hora DESC, rp.id DESC
        ),
        vinculo_ativo_mais_antigo AS (
            SELECT DISTINCT ON (a.user_id) a.user_id, a.tenant_id
              FROM ativos a
             ORDER BY a.user_id, a.created_at ASC, a.id ASC
        ),
        ultima_batida AS (
            SELECT DISTINCT ON (rp.user_id) rp.user_id, rp.tenant_id
              FROM registro_ponto rp
             ORDER BY rp.user_id, rp.data_hora DESC, rp.id DESC
        )
        UPDATE jornada_colaborador j
           SET tenant_id = COALESCE(
                   (SELECT uba.tenant_id FROM ultima_batida_entre_ativos uba WHERE uba.user_id = j.user_id),
                   (SELECT vma.tenant_id FROM vinculo_ativo_mais_antigo vma WHERE vma.user_id = j.user_id),
                   (SELECT ub.tenant_id FROM ultima_batida ub WHERE ub.user_id = j.user_id))
         WHERE j.tenant_id IS NULL
        SQL;

    /** Pessoas com vários vínculos ativos e nenhuma batida neles: a jornada foi para o vínculo mais antigo. */
    public const SQL_CONTA_VARIOS_ATIVOS_SEM_BATIDA = <<<'SQL'
        SELECT COUNT(*)
          FROM jornada_colaborador j
         WHERE (SELECT COUNT(*) FROM user_tenant ut JOIN tenant t ON t.id = ut.tenant_id
                 WHERE ut.user_id = j.user_id AND ut.is_active AND t.is_active) > 1
           AND NOT EXISTS (SELECT 1 FROM registro_ponto rp
                             JOIN user_tenant ut ON ut.user_id = rp.user_id AND ut.tenant_id = rp.tenant_id AND ut.is_active
                             JOIN tenant t ON t.id = rp.tenant_id AND t.is_active
                            WHERE rp.user_id = j.user_id)
        SQL;

    /** Jornadas sem vínculo ativo e sem batida nenhuma: ficam órfãs e são apagadas. */
    public const SQL_CONTA_ORFAS = <<<'SQL'
        SELECT COUNT(*)
          FROM jornada_colaborador j
         WHERE NOT EXISTS (SELECT 1 FROM user_tenant ut JOIN tenant t ON t.id = ut.tenant_id
                            WHERE ut.user_id = j.user_id AND ut.is_active AND t.is_active)
           AND NOT EXISTS (SELECT 1 FROM registro_ponto rp WHERE rp.user_id = j.user_id)
        SQL;

    public const SQL_APAGA_BLOCOS_ORFAOS = 'DELETE FROM bloco_jornada_colaborador WHERE jornada_colaborador_id IN (SELECT id FROM jornada_colaborador WHERE tenant_id IS NULL)';

    public const SQL_APAGA_JORNADAS_ORFAS = 'DELETE FROM jornada_colaborador WHERE tenant_id IS NULL';

    /** Regra 5: a chave fica ligada só onde houve batida nos últimos 30 dias. */
    public const SQL_CHAVE_REGISTRA_PONTO = <<<'SQL'
        UPDATE user_tenant ut
           SET registra_ponto = EXISTS (
                   SELECT 1 FROM registro_ponto rp
                    WHERE rp.user_id = ut.user_id
                      AND rp.tenant_id = ut.tenant_id
                      AND rp.data_hora >= LOCALTIMESTAMP - INTERVAL '30 days')
        SQL;

    public function getDescription(): string
    {
        return 'Jornada pessoal por escritório e chave Registra ponto eletrônico no vínculo';
    }

    public function up(Schema $schema): void
    {
        $this->contarCasosDeAviso();

        $this->addSql('ALTER TABLE jornada_colaborador ADD tenant_id INT DEFAULT NULL');
        $this->addSql(self::SQL_DESTINO_DA_JORNADA);
        $this->addSql(self::SQL_APAGA_BLOCOS_ORFAOS);
        $this->addSql(self::SQL_APAGA_JORNADAS_ORFAS);
        $this->addSql('ALTER TABLE jornada_colaborador ALTER tenant_id SET NOT NULL');
        $this->addSql('DROP INDEX uniq_3e2fe347a76ed395');
        $this->addSql('CREATE INDEX IDX_3E2FE347A76ED395 ON jornada_colaborador (user_id)');
        $this->addSql('CREATE INDEX IDX_3E2FE3479033212A ON jornada_colaborador (tenant_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_jornada_colaborador_user_tenant ON jornada_colaborador (user_id, tenant_id)');
        $this->addSql('ALTER TABLE jornada_colaborador ADD CONSTRAINT FK_3E2FE3479033212A FOREIGN KEY (tenant_id) REFERENCES tenant (id) NOT DEFERRABLE');

        $this->addSql('ALTER TABLE user_tenant ADD registra_ponto BOOLEAN DEFAULT false NOT NULL');
        $this->addSql(self::SQL_CHAVE_REGISTRA_PONTO);
        $this->addSql('ALTER TABLE user_tenant ALTER registra_ponto SET DEFAULT true');
    }

    /**
     * Volta a uma jornada por pessoa. Quem ficou com jornada em mais de um escritório depois do up perde as outras: fica
     * a mais antiga (menor id), que é a que veio do backfill. A chave sai junto com a coluna. As jornadas órfãs que o up
     * apagou (sem vínculo e sem nenhuma batida) não voltam: o down não as recupera.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_tenant DROP registra_ponto');

        $this->addSql('DELETE FROM bloco_jornada_colaborador b USING jornada_colaborador j WHERE b.jornada_colaborador_id = j.id AND EXISTS (SELECT 1 FROM jornada_colaborador o WHERE o.user_id = j.user_id AND o.id < j.id)');
        $this->addSql('DELETE FROM jornada_colaborador j WHERE EXISTS (SELECT 1 FROM jornada_colaborador o WHERE o.user_id = j.user_id AND o.id < j.id)');
        $this->addSql('ALTER TABLE jornada_colaborador DROP CONSTRAINT FK_3E2FE3479033212A');
        $this->addSql('DROP INDEX uq_jornada_colaborador_user_tenant');
        $this->addSql('DROP INDEX IDX_3E2FE3479033212A');
        $this->addSql('DROP INDEX IDX_3E2FE347A76ED395');
        $this->addSql('ALTER TABLE jornada_colaborador DROP tenant_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_3e2fe347a76ed395 ON jornada_colaborador (user_id)');
    }

    /** As duas saídas da regra 4 que o dono pediu para contar: vínculo mais antigo e jornada órfã apagada. */
    private function contarCasosDeAviso(): void
    {
        $varios = (int) $this->connection->fetchOne(self::SQL_CONTA_VARIOS_ATIVOS_SEM_BATIDA);
        $orfas = (int) $this->connection->fetchOne(self::SQL_CONTA_ORFAS);

        $this->write(sprintf('Jornadas com vários vínculos ativos e sem batida (foram para o vínculo mais antigo): %d', $varios));
        $this->write(sprintf('Jornadas órfãs apagadas (sem vínculo ativo e sem batida): %d', $orfas));
    }
}
