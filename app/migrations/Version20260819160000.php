<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renomeia o módulo DJEN para "Push Processual" no rótulo da permissão (2026-08-19).
 *
 * 🔑 SÓ DADO — nenhuma coluna, tabela ou índice é tocado.
 *
 * O `code` continua `modules.djen.view` de propósito. Ele é a chave que o `PermissionChecker` compara
 * e que 5 linhas de `tenant_role_permission` referenciam **em produção**; trocá-lo seria renomear o
 * modelo de autorização, que não é o que se pediu. O rótulo é outra coisa: `TenantRoleType` monta a
 * lista de permissões da tela de papéis com `$permission->getDescription()`, então a `description`
 * gravada aqui é literalmente o texto que o usuário lê ao montar um perfil.
 *
 * Por isso a fixture sozinha não bastava: `PermissionFixture` semeia dev e teste, não produção. Sem
 * esta migration a tela de papéis em produção continuaria dizendo "Acesso ao módulo DJEN" depois do
 * deploy — o único lugar da interface onde o nome antigo sobreviveria à renomeação.
 *
 * Idempotente pelo `WHERE code`: re-execução não duplica nem falha, e num banco onde a permissão
 * ainda não exista o UPDATE simplesmente não afeta linha nenhuma.
 */
final class Version20260819160000 extends AbstractMigration
{
    public const CODIGO = 'modules.djen.view';
    public const DESCRICAO_NOVA = 'Acesso ao módulo Push Processual (publicações/intimações)';
    public const DESCRICAO_ANTIGA = 'Acesso ao módulo DJEN (publicações/intimações)';

    public function getDescription(): string
    {
        return 'Push Processual: rótulo da permissão modules.djen.view passa a usar o nome novo do módulo '
            . '(só description; o code e os vínculos de papel ficam intactos).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE permission SET description = :nova WHERE code = :code',
            ['nova' => self::DESCRICAO_NOVA, 'code' => self::CODIGO]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE permission SET description = :antiga WHERE code = :code',
            ['antiga' => self::DESCRICAO_ANTIGA, 'code' => self::CODIGO]
        );
    }
}
