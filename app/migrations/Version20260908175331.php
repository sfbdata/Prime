<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Índice único parcial em `cobranca_caso.pasta_judicial_id` — defesa em profundidade contra o
 * Problema B (pasta judicial vinculada a mais de um Caso de Cobrança, medido em produção: mesma
 * pessoa em unidades diferentes, e pessoas diferentes na mesma pasta). As camadas de aplicação
 * (`JudicializarCasoUseCase::pastaExistenteDoTenant`, busca de vincular) já recusam o caso novo;
 * este índice é o último cinto de segurança, no banco.
 *
 * Índice PARCIAL/FUNCIONAL: o Doctrine ORM não representa `WHERE ... IS NOT NULL` em atributo de
 * mapeamento (mesmo padrão de `uniq_cobranca_obrigacao_ref_competencia`,
 * `Version20260730120000`), por isso é SQL cru aqui e não aparece em `#[ORM\Index]` na entidade —
 * o próximo `doctrine:schema:update --dump-sql` vai propor `DROP INDEX` dele, e essa proposta deve
 * ser IGNORADA (ruído conhecido, documentado em `Version20260901153507`).
 *
 * ⚠️ ORDEM OBRIGATÓRIA em produção: só aplicar DEPOIS de rodar
 * `app:cobranca:corrigir-pasta-judicial-duplicada --aplicar` — a criação do índice falha com
 * violação de unicidade enquanto os 2 casos duplicados conhecidos (ver
 * `docs/specs/cobranca-desvincular-judicializacao.md`) ainda existirem.
 */
final class Version20260908175331 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Índice único parcial em cobranca_caso.pasta_judicial_id (Problema B — pasta duplicada)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_cobranca_caso_pasta_judicial ON cobranca_caso (pasta_judicial_id) WHERE (pasta_judicial_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_cobranca_caso_pasta_judicial');
    }
}
