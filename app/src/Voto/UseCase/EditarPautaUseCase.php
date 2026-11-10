<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\PautaInput;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\OpcoesDaPauta;

/**
 * Altera título, descrição e opções de uma pauta da assembleia em rascunho. As opções de voto são trocadas pelas
 * novas (em rascunho não há voto nenhum); a abstenção fica.
 */
final class EditarPautaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly OpcaoDaPautaRepository $opcoes,
        private readonly OpcoesDaPauta $opcoesDaPauta,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId, PautaInput $input): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $pauta = $this->pautas->buscarDaAssembleia($pautaId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirPautasAlteraveis();
        $novas = $this->opcoesDaPauta->validar(trim($input->titulo), $input->opcoes);
        $pauta->editar($input->titulo, $input->descricaoLimpa());
        $this->trocarOpcoes($pauta, $novas);
        $this->pautas->salvar($pauta, flush: true);
    }

    /** @param list<array{numero: int, rotulo: string}> $novas */
    private function trocarOpcoes(PautaDaAssembleia $pauta, array $novas): void
    {
        foreach ($pauta->getOpcoesDeVoto() as $antiga) {
            $pauta->getOpcoes()->removeElement($antiga);
            $this->opcoes->remover($antiga);
        }
        foreach ($novas as $opcao) {
            new OpcaoDaPauta($pauta, $opcao['numero'], $opcao['rotulo']);
        }
    }
}
