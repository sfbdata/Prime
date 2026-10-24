<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Tenant\Service\SituacaoDaConta;
use App\TrabalhoExterno\Entity\ConviteDemanda;

/**
 * Se o convite ainda pode ser aberto e aceito: o convite em si (não revogado, dentro da validade) e o escritório dono
 * da demanda no ar. É a regra única dos três caminhos do link (abrir, criar a conta e aceitar logado): o escritório
 * fora do ar tem a mesma resposta do convite vencido, sem revelar a situação
 * (docs/specs/te-convidado-escritorio-fora-do-ar.md).
 */
final class ConviteDisponivel
{
    public function __construct(
        private readonly SituacaoDaConta $situacao,
    ) {
    }

    public function para(ConviteDemanda $convite, \DateTimeImmutable $hoje): bool
    {
        return $convite->aceitavel($hoje) && !$this->situacao->foraDoAr($convite->getDemanda()->getTenant());
    }
}
