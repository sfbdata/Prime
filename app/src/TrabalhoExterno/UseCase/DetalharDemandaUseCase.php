<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Service\ApresentadorDeDemanda;
use App\TrabalhoExterno\Service\ContadorDeNaoLidas;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;

/** O painel lateral de uma demanda, no recorte do perfil. Consulta: não grava nada. */
final class DetalharDemandaUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly ApresentadorDeDemanda $apresentador,
        private readonly ContadorDeNaoLidas $contador,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Contexto $ctx, string $codigo): array
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        $naoLidas = $this->contador->porDemanda([[$demanda, $ctx]]);

        return $this->apresentador->detalhe($ctx, $demanda, $naoLidas[(int) $demanda->getId()] ?? 0);
    }
}
