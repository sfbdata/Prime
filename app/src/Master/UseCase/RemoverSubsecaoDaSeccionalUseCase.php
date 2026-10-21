<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\RemoverSubsecaoDaSeccionalInput;
use App\Master\Entity\SubsecaoOab;
use App\Master\Exception\SubsecaoNaoEncontrada;
use App\Master\Repository\SubsecaoOabRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;

/**
 * Remove o endereço de uma subseção da OAB (spec docs/specs/master-oab-estados-que-faltam.md, "SUBSEÇÕES";
 * desenho 05, Perfis 1.2, VS 21.10.26, L489: botão "Remover" da linha).
 *
 * Quem dispara: o Master, no "Remover" da janela da OAB, depois da confirmação do painel (ROLE_SUPER_ADMIN no
 * controller).
 * Objetivo: tirar da lista um endereço errado ou que deixou de valer.
 * Pré-condições: a UF é uma das 27 (o controller recusa as outras com 404).
 * Fluxo principal: busca a subseção pelo id DENTRO da seccional da URL e a apaga dentro do escopo de plataforma.
 * Fluxos alternativos: id que não existe ou que é de outra seccional dá 404 e nada é apagado.
 * Pós-condições: a subseção some da janela para todos os Masters; a remoção fica na trilha do Master.
 * Auditoria: SubsecaoOab é Auditavel; a linha "delete" do audit_log fica sem escritório.
 */
final class RemoverSubsecaoDaSeccionalUseCase
{
    public function __construct(
        private readonly SubsecaoOabRepository $subsecoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(RemoverSubsecaoDaSeccionalInput $input): SubsecaoOab
    {
        $subsecao = $this->subsecoes->buscarNaSeccional($input->id, $input->uf) ?? throw new SubsecaoNaoEncontrada(SubsecaoNaoEncontrada::RECUSA);
        $this->escopoDePlataforma->executar(fn () => $this->subsecoes->remover($subsecao, flush: true));

        return $subsecao;
    }
}
