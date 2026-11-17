<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\FotosDasCandidaturas;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * "Duplicar" do menu da pauta (desenho 18, etapa 4): copia a pauta, com regras, opções e dados das candidaturas, logo
 * abaixo da original (com a foto das candidaturas, em arquivo novo) e renumera a lista. Só em rascunho. O contador de votos da cópia nasce zerado.
 */
final class DuplicarPautaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly FotosDasCandidaturas $fotos,
    ) {
    }

    /** @return int o id da cópia */
    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId): int
    {
        return $this->serializacao->executar($tenant, $assembleiaId, fn (): int => $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $pautaId));
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, int $pautaId): int
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirPautasAlteraveis();
        $origem = $this->pautas->buscarDaAssembleia($pautaId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();

        $fila = $assembleia->getPautas()->toArray();
        usort($fila, static fn (PautaDaAssembleia $a, PautaDaAssembleia $b): int => [$a->getOrdem(), $a->getId()] <=> [$b->getOrdem(), $b->getId()]);

        $copia = new PautaDaAssembleia($assembleia, $origem->getOrdem(), $origem->getTitulo(), $origem->getDescricao(), $origem->getTipo());
        $segunda = $origem->getQuorumSegunda();
        $copia->configurar(
            $origem->getCriterio(),
            $origem->getBase(),
            $origem->getDigitos(),
            $origem->getVagas(),
            (int) $origem->getQuorumPrimeira(),
            null === $segunda ? null : (int) $segunda,
        );
        $copia->definirParticipacao(null, null, $origem->getGrupos(), $origem->getOrdemDasCandidaturas(), $origem->getSigilo());
        foreach ($origem->getOpcoes() as $opcao) {
            (new OpcaoDaPauta($copia, $opcao->getNumero(), $opcao->getRotulo(), $opcao->isAbstencao()))
                ->detalhar($opcao->getDescricao(), $opcao->getPropostas(), $opcao->getComposicao(), $opcao->getUnidadeCandidato());
        }

        $posicao = (int) array_search($origem, $fila, true);
        array_splice($fila, $posicao + 1, 0, [$copia]);
        foreach ($fila as $i => $pauta) {
            $pauta->reposicionar($i + 1);
        }
        $copias = $this->fotos->copiar($origem, $copia);
        $this->fotos->persistirComCopias($copias, fn () => $this->pautas->salvar($copia, flush: true));

        return (int) $copia->getId();
    }
}
