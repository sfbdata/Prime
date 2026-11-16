<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\PautaInput;
use App\Voto\DTO\PlanoDeFotosDaPauta;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\FotosDasCandidaturas;
use App\Voto\Service\OpcoesDaPauta;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Altera título, descrição, regras (se a tela manda) e opções de uma pauta da assembleia em rascunho. As opções de voto são trocadas pelas
 * novas (em rascunho não há voto nenhum); a abstenção fica.
 *
 * A foto 3x4 da candidatura (só na eleição de pessoas) segue o mesmo "Salvar": a que a tela devolve fica com a opção,
 * a enviada entra no lugar e a que deixou de valer só sai do armazenamento depois do COMMIT ({@see FotosDasCandidaturas}).
 */
final class EditarPautaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly OpcaoDaPautaRepository $opcoes,
        private readonly OpcoesDaPauta $opcoesDaPauta,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly FotosDasCandidaturas $fotos,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $pautaId, PautaInput $input): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $pautaId, $input): void {
            $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $pautaId, $input);
        });
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, int $pautaId, PautaInput $input): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $pauta = $this->pautas->buscarDaAssembleia($pautaId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirPautasAlteraveis();
        $digitos = $input->regras->digitos ?? $pauta->getDigitos();
        $novas = $this->opcoesDaPauta->validar(trim($input->titulo), $input->opcoes, $digitos, $input->tipo->eleitoral());
        $plano = $this->fotos->planejar($pauta, $input->opcoes, $novas, $input->tipo->eleitoral());
        $this->fotos->gravar($pauta, $plano);
        $this->fotos->confirmar($plano, function () use ($pauta, $input, $novas, $plano): void {
            $pauta->editar($input->titulo, $input->descricaoLimpa(), $input->tipo);
            if (null !== $input->regras) {
                $r = $input->regras;
                $pauta->configurar($r->criterio, $r->base, $r->digitos, $r->vagas, $r->quorumPrimeira, $r->quorumSegunda);
            }
            $this->trocarOpcoes($pauta, $novas, $plano);
        }, fn (bool $flush) => $this->pautas->salvar($pauta, $flush));
    }

    /** @param list<array{numero: int, rotulo: string, descricao: ?string, propostas: ?string, composicao: ?string, unidade: ?string}> $novas */
    private function trocarOpcoes(PautaDaAssembleia $pauta, array $novas, PlanoDeFotosDaPauta $plano): void
    {
        foreach ($pauta->getOpcoesDeVoto() as $antiga) {
            $pauta->getOpcoes()->removeElement($antiga);
            $this->opcoes->remover($antiga);
        }
        foreach ($novas as $opcao) {
            $nova = new OpcaoDaPauta($pauta, $opcao['numero'], $opcao['rotulo']);
            $nova->detalhar($opcao['descricao'], $opcao['propostas'], $opcao['composicao'], $opcao['unidade']);
            $nova->definirFoto($plano->nomeDe($opcao['numero']));
        }
    }
}
