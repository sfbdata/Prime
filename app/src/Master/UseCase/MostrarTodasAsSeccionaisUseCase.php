<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Entity\SeccionalOab;
use App\Master\Repository\SeccionalOabRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * "Mostrar todas" do cabeçalho do bloco das seccionais (spec docs/specs/master-seccionais-oab.md; desenho 05,
 * Perfis 1.2, VS 21.10.26, L902: `secTodas` limpa o filtro; não existe "Ocultar todas").
 *
 * Quem dispara: o Master, no botão do cabeçalho "SECCIONAIS VISÍVEIS" (ROLE_SUPER_ADMIN no controller).
 * Objetivo: voltar a mostrar no mapa todas as seccionais ocultas.
 * Pré-condições: nenhuma.
 * Fluxo principal: carrega as 27, marca como visíveis as ocultas e grava num flush só, dentro do escopo de plataforma.
 * Fluxos alternativos: nenhuma oculta: nada é gravado (o botão diz "Todas visíveis" e o clique não muda nada).
 * Pós-condições: todas as seccionais visíveis para todos os Masters.
 * Auditoria: SeccionalOab é Auditavel; uma linha do audit_log por seccional que voltou, sem escritório.
 */
final class MostrarTodasAsSeccionaisUseCase
{
    public function __construct(
        private readonly SeccionalOabRepository $seccionais,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    /** @return int quantas voltaram a aparecer */
    public function executar(): int
    {
        $ocultas = array_values(array_filter($this->seccionais->todasEmOrdem(), static fn (SeccionalOab $s): bool => !$s->isVisivel()));
        if ($ocultas === []) {
            return 0;
        }

        array_walk($ocultas, static fn (SeccionalOab $s) => $s->mostrar());
        $this->escopoDePlataforma->executar(fn () => $this->seccionais->salvarTodas($ocultas));

        return count($ocultas);
    }
}
