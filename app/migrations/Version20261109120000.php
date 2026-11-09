<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BlueJus Voto, fase A1 (spec docs/specs/bluejus-voto.md, seção 7, M1). Só dados, idempotente:
 *
 * 1. O recurso `voto` nasce DESLIGADO em `recurso_estado`. Sem linha a chave vale como ativa
 *    (RecursoEstado, "Sem linha = ativo"), e o Voto não pode aparecer para os escritórios antes da
 *    liberação do Master (decisão D5 de 09/11/2026). O motivo fica nulo: a página de bloqueio mostra a
 *    mensagem padrão.
 * 2. A permissão `modules.voto.view`. Não é concedida a nenhum perfil existente: o perfil de sistema
 *    passa pelo bypass e os demais ganham a permissão no painel de Perfis, quando o escritório usar o Voto.
 */
final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BlueJus Voto: recurso voto desligado e permissão modules.voto.view';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO recurso_estado (chave, ativo, motivo, ate, alterado_por_id, alterado_em)
            SELECT 'voto', false, NULL, NULL, NULL, NOW()
            WHERE NOT EXISTS (SELECT 1 FROM recurso_estado WHERE chave = 'voto')
        SQL);
        // "group" é palavra reservada no Postgres, por isso vem entre aspas.
        $this->addSql(<<<'SQL'
            INSERT INTO permission (code, description, "group")
            SELECT 'modules.voto.view', 'Acesso ao módulo Voto (eleições e votações)', 'modules'
            WHERE NOT EXISTS (SELECT 1 FROM permission WHERE code = 'modules.voto.view')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM tenant_role_permission WHERE permission_id = (SELECT id FROM permission WHERE code = 'modules.voto.view')");
        $this->addSql("DELETE FROM permission WHERE code = 'modules.voto.view'");
        $this->addSql("DELETE FROM recurso_estado WHERE chave = 'voto'");
    }
}
