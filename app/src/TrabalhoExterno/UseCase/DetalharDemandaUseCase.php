<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Service\ApresentadorDeDemanda;
use App\TrabalhoExterno\Service\ContadorDeNaoLidas;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PreviaDePerfil;

/** O painel lateral de uma demanda, no recorte do perfil. Consulta: não grava nada. */
final class DetalharDemandaUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly ApresentadorDeDemanda $apresentador,
        private readonly ContadorDeNaoLidas $contador,
        private readonly PreviaDePerfil $previa,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Contexto $ctx, string $codigo): array
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        $naoLidas = $this->contador->porDemanda([[$demanda, $ctx]]);

        return $this->apresentador->detalhe($ctx, $demanda, $naoLidas[(int) $demanda->getId()] ?? 0);
    }

    /**
     * O painel lateral como `$como` veria a demanda (spec §9). Localiza no escopo do Master; se o
     * perfil pré-visualizado não a veria (ex.: convidado sem convite aceito), é "não encontrada".
     *
     * @return array<string, mixed>
     */
    public function executarPrevia(Contexto $master, string $codigo, Perfil $como): array
    {
        [$demanda] = $this->localizador->localizar($master, $codigo);
        $ctx = $this->previa->paraDemanda($master, $como, $demanda) ?? throw new DemandaNaoEncontrada();
        $naoLidas = $this->contador->porDemanda([[$demanda, $ctx]]);

        return $this->apresentador->detalhe($ctx, $demanda, $naoLidas[(int) $demanda->getId()] ?? 0);
    }
}
