<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\DefinirVisibilidadeDaSeccionalInput;
use App\Master\Entity\SeccionalOab;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\SeccionalOabRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * Mostra ou oculta uma seccional no mapa (spec docs/specs/master-seccionais-oab.md, "Mostrar ou ocultar no mapa";
 * desenho 05, Perfis 1.2, VS 21.10.26, L902: `x.tgCtx`, botão direito no chip).
 *
 * Quem dispara: o Master, no botão direito sobre o chip da UF (ROLE_SUPER_ADMIN no controller).
 * Objetivo: escolher quais seccionais aparecem no mapa (o marcador some do iframe; o chip fica apagado).
 * Pré-condições: a UF é uma das 27 (o controller recusa as outras com 404) e a linha da seccional existe.
 * Fluxo principal: carrega a seccional, põe `visivel` no estado pedido e grava dentro do escopo de plataforma.
 * Fluxos alternativos: linha ausente é 404; pedido sem `visivel` booleano é recusado (422); pedir o estado que já
 * vale não muda nada (idempotente: dois cliques rápidos ou um reenvio não invertem de volta).
 * Pós-condições: o filtro vale para todos os Masters (guardado no banco, compartilhado: padrão adotado, a confirmar
 * com o dono).
 * Auditoria: SeccionalOab é Auditavel; a linha do audit_log fica sem escritório (trilha do Master).
 */
final class DefinirVisibilidadeDaSeccionalUseCase
{
    public function __construct(
        private readonly SeccionalOabRepository $seccionais,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(DefinirVisibilidadeDaSeccionalInput $input): SeccionalOab
    {
        $seccional = $this->seccionais->buscarPorUf($input->uf) ?? throw new SeccionalNaoEncontrada('Seccional não encontrada.');
        $visivel = $input->visivel ?? throw new SeccionalRecusada(SeccionalRecusada::RECUSA_VISIBILIDADE);
        if ($seccional->isVisivel() === $visivel) {
            return $seccional;
        }
        $seccional->definirVisibilidade($visivel);
        $this->escopoDePlataforma->executar(fn () => $this->seccionais->salvar($seccional, flush: true));

        return $seccional;
    }
}
