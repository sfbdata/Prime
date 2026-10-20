<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tira dos registros ANTIGOS da auditoria o conteúdo dos compromissos "Só eu" (título, descrição e local),
 * como o `AuditLogSubscriber` já faz com os novos desde o #81. Autorizado pelo dono em 19/10/2026 (cerca de
 * 80 registros em produção, um escritório). Ação, quem, quando e os demais campos ficam; a marca
 * `conteudo_particular` diz que algo foi omitido, igual aos registros novos.
 *
 * Um registro é de compromisso "Só eu" quando:
 *  - ele mesmo mostra a visibilidade "somente_eu" (antes, depois ou na mudança); a criação não guarda o id
 *    do compromisso, então só dá para saber por aqui; ou
 *  - o compromisso foi "Só eu" em algum registro dele ou é "Só eu" hoje (vale para o compromisso inteiro,
 *    como o subscriber, que esconde também a mudança que tira o "Só eu").
 *
 * A mudança de participantes leva o título no rótulo do dono: `SQL_DONO_DA_COLECAO` troca pelo rótulo
 * particular.
 *
 * Os dois nomes da classe entram porque o #63 move o `Evento` para `App\Agenda\Entity` (e renomeia os
 * registros); esta migration pode rodar antes ou depois dele.
 *
 * Irreversível de propósito: o conteúdo apagado não volta.
 */
final class Version20261019235500 extends AbstractMigration
{
    public const SQL_LIMPEZA = <<<'SQL'
        WITH ev AS (
            SELECT id, entity_id, changes::jsonb AS c
              FROM audit_log
             WHERE entity_class IN ('App\Entity\Agenda\Evento', 'App\Agenda\Entity\Evento')
               AND changes IS NOT NULL
        ),
        proprio AS (
            SELECT id, entity_id
              FROM ev
             WHERE c #>> '{diff,after,visibilidade}' = 'somente_eu'
                OR c #>> '{diff,before,visibilidade}' = 'somente_eu'
                OR c #>> '{diff,changes,visibilidade,0}' = 'somente_eu'
                OR c #>> '{diff,changes,visibilidade,1}' = 'somente_eu'
        ),
        compromissos AS (
            SELECT entity_id FROM proprio WHERE entity_id IS NOT NULL
            UNION
            SELECT id::text FROM evento WHERE visibilidade = 'somente_eu'
        )
        UPDATE audit_log a
           SET changes = jsonb_set(
                   ev.c
                       #- '{diff,after,titulo}' #- '{diff,after,descricao}' #- '{diff,after,local}'
                       #- '{diff,before,titulo}' #- '{diff,before,descricao}' #- '{diff,before,local}'
                       #- '{diff,changes,titulo}' #- '{diff,changes,descricao}' #- '{diff,changes,local}',
                   '{diff,conteudo_particular}',
                   'true'::jsonb
               )::json
          FROM ev
         WHERE a.id = ev.id
           AND jsonb_typeof(ev.c -> 'diff') = 'object'
           AND (ev.id IN (SELECT id FROM proprio) OR ev.entity_id IN (SELECT entity_id FROM compromissos))
        SQL;

    /**
     * Mudança de participantes: o rótulo do dono (`association_events[].owner.label`) é o título. Vira o
     * rótulo particular do subscriber quando o dono é "Só eu" pelo id (hoje ou em algum registro) ou, no
     * registro gravado no mesmo flush da criação (o dono ainda sem id), quando a criação "Só eu" é do mesmo
     * instante e da mesma pessoa. Duas criações no mesmo segundo pela mesma pessoa, uma "Só eu" e outra não,
     * escondem as duas: na dúvida, esconde.
     */
    public const SQL_DONO_DA_COLECAO = <<<'SQL'
        WITH compromissos AS (
            SELECT entity_id
              FROM audit_log
             WHERE entity_class IN ('App\Entity\Agenda\Evento', 'App\Agenda\Entity\Evento')
               AND entity_id IS NOT NULL
               AND (changes::jsonb #>> '{diff,after,visibilidade}' = 'somente_eu'
                    OR changes::jsonb #>> '{diff,before,visibilidade}' = 'somente_eu'
                    OR changes::jsonb #>> '{diff,changes,visibilidade,0}' = 'somente_eu'
                    OR changes::jsonb #>> '{diff,changes,visibilidade,1}' = 'somente_eu')
            UNION
            SELECT id::text FROM evento WHERE visibilidade = 'somente_eu'
        )
        UPDATE audit_log a
           SET changes = jsonb_set(
                   jsonb_set(
                       a.changes::jsonb,
                       '{diff,association_events}',
                       (SELECT jsonb_agg(jsonb_set(e, '{owner,label}', '"Compromisso particular"'::jsonb))
                          FROM jsonb_array_elements(a.changes::jsonb #> '{diff,association_events}') e)
                   ),
                   '{diff,conteudo_particular}',
                   'true'::jsonb
               )::json
         WHERE a.entity_class IN ('App\Entity\Agenda\Evento', 'App\Agenda\Entity\Evento')
           AND jsonb_typeof(a.changes::jsonb #> '{diff,association_events}') = 'array'
           AND jsonb_array_length(a.changes::jsonb #> '{diff,association_events}') > 0
           AND (
               EXISTS (SELECT 1
                         FROM jsonb_array_elements(a.changes::jsonb #> '{diff,association_events}') e
                        WHERE e #>> '{owner,id}' IN (SELECT entity_id FROM compromissos))
               OR (a.entity_id IS NULL AND EXISTS (
                   SELECT 1
                     FROM audit_log c
                    WHERE c.entity_class = a.entity_class
                      AND c.action = 'create'
                      AND c.created_at = a.created_at
                      AND c.actor_user_id IS NOT DISTINCT FROM a.actor_user_id
                      AND c.id < a.id
                      AND c.changes::jsonb #>> '{diff,after,visibilidade}' = 'somente_eu'))
           )
        SQL;

    public function getDescription(): string
    {
        return 'Tira título, descrição e local dos registros antigos de auditoria dos compromissos Só eu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(self::SQL_LIMPEZA);
        $this->addSql(self::SQL_DONO_DA_COLECAO);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('O conteúdo apagado da auditoria não volta.');
    }
}
