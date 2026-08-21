<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enterra a demissão (2026-08-20) — spec `docs/specs/remover-colaborador-do-escritorio.md` §6.5/§6.6.
 *
 * A remoção de colaborador (Tasks 1-7 desta frente) substituiu a demissão por hard delete do
 * vínculo: `UserTenant::demitir()` e `::sair()` deixaram de existir, e a regra passou a ser
 * "vínculo existe = colaborador", sem estado intermediário. Isso deixou órfãos os vínculos que
 * já estavam `is_active = false` sob o regime antigo — 3 em produção e 3 no dev (decisão D8 da
 * spec) — porque `app_tenant_user_edit_role` já exige vínculo ativo, então nada na aplicação
 * consegue mais alcançá-los (o botão "Ver" da lista de desligados já dava 404 antes desta
 * migration). A limpeza fecha a regra única e tira esses nomes-fantasma dos seletores de
 * participante do Kanban, responsável de Tarefa e Agenda, que fazem JOIN sem `isActive`.
 *
 * `demitido_em` é dropada na mesma migration: depois da remoção do código morto, nada mais lê
 * nem escreve esse campo, e as linhas que o preenchiam deixam de existir por causa do DELETE
 * acima.
 */
final class Version20260820120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Apaga vinculos inativos e remove a coluna de demissao: a demissao deixou de existir.';
    }

    public function up(Schema $schema): void
    {
        // Regra nova: vínculo existe = colaborador. Não há mais estado intermediário.
        // Em produção isto apaga 3 linhas (decisão D8 da spec).
        $this->addSql('DELETE FROM user_tenant WHERE is_active = false');
        $this->addSql('ALTER TABLE user_tenant DROP demitido_em');
    }

    public function down(Schema $schema): void
    {
        // As linhas apagadas não voltam — o down só restaura a coluna.
        $this->addSql('ALTER TABLE user_tenant ADD demitido_em TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }
}
