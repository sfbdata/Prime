<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;

/** A chave "Aceitar resposta" do DESPACHO (desenho 06 de 24/10, `dpRespTg`, l.1392): só o Master liga e desliga. */
final class LiberarRespostasAoDespachoUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, bool $liberar): DemandaExterna
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podeDespachar($ctx, $demanda)) {
            throw new AcaoNaoPermitida('Só a Central BlueJus libera as respostas ao despacho.');
        }
        $demanda->liberarRespostasAoDespacho($liberar);
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
