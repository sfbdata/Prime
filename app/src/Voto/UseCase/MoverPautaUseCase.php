<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * "Mover para cima" e "Mover para baixo" do menu da pauta (desenho 18, etapa 4): troca a pauta de lugar com a vizinha
 * e renumera a lista de 1 em diante, só com a assembleia em rascunho. Na ponta da lista não há o que mover: segue
 * sem erro e sem mudança (o menu nem mostra o item).
 */
final class MoverPautaUseCase
{
    public const PARA_CIMA = -1;
    public const PARA_BAIXO = 1;

    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly SerializacaoDaConfiguracao $serializacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId, int $direcao): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $pautaId, $direcao): void {
            $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $pautaId, $direcao);
        });
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, int $pautaId, int $direcao): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirPautasAlteraveis();
        $pauta = $this->pautas->buscarDaAssembleia($pautaId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();

        $fila = $assembleia->getPautas()->toArray();
        usort($fila, static fn (PautaDaAssembleia $a, PautaDaAssembleia $b): int => [$a->getOrdem(), $a->getId()] <=> [$b->getOrdem(), $b->getId()]);
        $de = array_search($pauta, $fila, true);
        $para = false === $de ? -1 : $de + ($direcao < 0 ? -1 : 1);
        if (false !== $de && isset($fila[$para])) {
            [$fila[$de], $fila[$para]] = [$fila[$para], $fila[$de]];
        }
        foreach ($fila as $i => $item) {
            $item->reposicionar($i + 1);
        }
        $this->pautas->salvar($pauta, flush: true);
    }
}
