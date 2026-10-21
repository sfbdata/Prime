<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\AlternarVisibilidadeDaSeccionalInput;
use App\Master\Entity\SeccionalOab;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Repository\SeccionalOabRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * Mostra ou oculta uma seccional no mapa (spec docs/specs/master-seccionais-oab.md, "Mostrar ou ocultar no mapa";
 * desenho 05, Perfis 1.2, VS 21.10.26, L902: `x.tgCtx`, botão direito no chip).
 *
 * Quem dispara: o Master, no botão direito sobre o chip da UF (ROLE_SUPER_ADMIN no controller).
 * Objetivo: escolher quais seccionais aparecem no mapa (o marcador some do iframe; o chip fica apagado).
 * Pré-condições: a UF é uma das 27 (o controller recusa as outras com 404) e a linha da seccional existe.
 * Fluxo principal: carrega a seccional, inverte `visivel` e grava dentro do escopo de plataforma.
 * Fluxos alternativos: linha ausente é 404 e nada muda.
 * Pós-condições: o filtro vale para todos os Masters (guardado no banco, compartilhado: padrão adotado, a confirmar
 * com o dono).
 * Auditoria: SeccionalOab é Auditavel; a linha do audit_log fica sem escritório (trilha do Master).
 */
final class AlternarVisibilidadeDaSeccionalUseCase
{
    public function __construct(
        private readonly SeccionalOabRepository $seccionais,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(AlternarVisibilidadeDaSeccionalInput $input): SeccionalOab
    {
        $seccional = $this->seccionais->buscarPorUf($input->uf) ?? throw new SeccionalNaoEncontrada('Seccional não encontrada.');
        $seccional->alternarVisibilidade();
        $this->escopoDePlataforma->executar(fn () => $this->seccionais->salvar($seccional, flush: true));

        return $seccional;
    }
}
