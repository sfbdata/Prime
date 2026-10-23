<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Perfis 1.2 (spec docs/specs/perfis-1.2-masters.md §3): marca o Master primário com ROLE_MASTER_PRIMARIO.
 *
 * Só dado, nenhuma mudança de esquema. O papel vai para o único ROLE_SUPER_ADMIN, e SÓ se houver exatamente
 * um e ninguém ainda tiver ROLE_MASTER_PRIMARIO. Com zero ou mais de um Master não faz nada: o dono escolhe o
 * primário com `app:master:definir <email> --confirmar`.
 *
 * `roles` é `json` (não `jsonb`): a busca é textual pelo nome entre aspas (como no UserRepository) e a escrita
 * passa por jsonb. Só mexe em linha cujo `roles` é um array JSON; os demais papéis da conta ficam como estão.
 * Os nomes dos papéis estão escritos aqui de propósito (migration não depende de código da aplicação); a fonte
 * de verdade é App\Master\Service\RegraDosMasters.
 */
final class Version20261019120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Perfis 1.2: ROLE_MASTER_PRIMARIO para o Master único (Masters primário e secundários)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE "user"
               SET roles = (roles::jsonb || '["ROLE_MASTER_PRIMARIO"]'::jsonb)::json
             WHERE CAST(roles AS TEXT) LIKE '%"ROLE_SUPER_ADMIN"%'
               AND json_typeof(roles) = 'array'
               AND (SELECT COUNT(*) FROM "user" m WHERE CAST(m.roles AS TEXT) LIKE '%"ROLE_SUPER_ADMIN"%') = 1
               AND NOT EXISTS (SELECT 1 FROM "user" p WHERE CAST(p.roles AS TEXT) LIKE '%"ROLE_MASTER_PRIMARIO"%')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE "user"
               SET roles = COALESCE(
                       (SELECT json_agg(e.papel ORDER BY e.ordem)
                          FROM json_array_elements(roles) WITH ORDINALITY AS e(papel, ordem)
                         WHERE e.papel::text <> '"ROLE_MASTER_PRIMARIO"'),
                       '[]'::json)
             WHERE CAST(roles AS TEXT) LIKE '%"ROLE_MASTER_PRIMARIO"%'
               AND json_typeof(roles) = 'array'
            SQL);
    }
}
