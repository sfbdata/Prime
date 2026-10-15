<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\TransicaoInvalida;

/**
 * A demanda pedida, desde que em execução e com quem pede autorizado a registrar andamento
 * (checklist e "Registrar andamento" da aba Execução).
 */
final class GuardaDeExecucao
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
    ) {
    }

    public function demandaEmExecucao(Contexto $ctx, string $codigo): DemandaExterna
    {
        $demanda = $this->localizador->localizar($ctx, $codigo);
        if ($demanda->getSituacao() !== Situacao::Execucao) {
            throw new TransicaoInvalida('O andamento só pode ser registrado durante a execução.');
        }
        if (!$this->politica->podeRegistrarAndamento($ctx, $demanda)) {
            throw new AcaoNaoPermitida('Você não pode registrar andamento nesta demanda.');
        }

        return $demanda;
    }
}
