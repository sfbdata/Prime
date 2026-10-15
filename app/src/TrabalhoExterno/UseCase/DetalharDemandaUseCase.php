<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Repository\MensagemDemandaRepository;
use App\TrabalhoExterno\Service\ApresentadorDeDemanda;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;

/** O painel lateral de uma demanda, no recorte do perfil. Consulta: não grava nada. */
final class DetalharDemandaUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly ApresentadorDeDemanda $apresentador,
        private readonly MensagemDemandaRepository $mensagens,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Contexto $ctx, string $codigo): array
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        $naoLidas = $this->mensagens->naoLidasPorDemanda([$demanda], $ctx->usuario, $ctx->ehInterno());

        return $this->apresentador->detalhe($ctx, $demanda, $naoLidas[(int) $demanda->getId()] ?? 0);
    }
}
