<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ConferenciaDaPublicacaoOutput;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Entity\MembroDaEquipe;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\MembroDaEquipeRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\ConfiguracaoDaPublicacao;
use App\Voto\Service\LeituraDaAssembleia;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\Service\SomaDasFracoes;

/**
 * Consulta de revisão do gestor. Usa a mesma validação da publicação, preservando o rascunho. Cargo da equipe própria sem
 * pessoa indicada é AVISO (desenho 18, passo 7: "N cargo(s) ainda sem pessoa indicada pela Administração."), não bloqueio.
 */
final class ConferirPublicacaoDaAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly LeituraDaAssembleia $leitura,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly MembroDaEquipeRepository $membros,
    ) {
    }

    public function executar(Tenant $tenant, int $id): ConferenciaDaPublicacaoOutput
    {
        return $this->serializacao->executar($tenant, $id, fn (): ConferenciaDaPublicacaoOutput => $this->executarComConfiguracaoReservada($tenant, $id));
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $id): ConferenciaDaPublicacaoOutput
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($id, $tenant) ?? throw new AssembleiaNaoEncontradaException();
        $pendencia = null;
        try {
            $assembleia->exigirPublicacaoPossivel();
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            $pendencia = $recusa->getMessage();
        }

        $soma = PesoDoVoto::Fracao === $assembleia->getPeso() ? $this->unidades->somarFracoes($assembleia) : null;

        $equipe = $this->membros->daAssembleia($assembleia);
        $semPessoa = array_filter($equipe, static fn (MembroDaEquipe $m): bool => $m->aDesignar());

        return new ConferenciaDaPublicacaoOutput(
            $this->leitura->montar($assembleia),
            ConfiguracaoDaPublicacao::codigo($assembleia),
            $pendencia,
            $soma,
            null !== $soma && SomaDasFracoes::foraDoEsperado($soma),
            \count($equipe),
            \count($semPessoa),
        );
    }
}
