<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\DadosDaUnidadeInput;
use App\Voto\DTO\LinhaDeUnidade;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\LeitorDaListaDeUnidades;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Corrige o cadastro de uma unidade (unidade, bloco, situação, fração, responsável, e-mail) enquanto nenhuma pauta foi
 * aberta. Posse: a unidade só vale se for desta assembleia e deste escritório. Mesma validação da planilha; a unidade
 * não pode virar a de outra já cadastrada. O link continua o mesmo e o telefone não muda.
 */
final class EditarUnidadeUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly LeitorDaListaDeUnidades $leitor,
        private readonly SerializacaoDaConfiguracao $serializacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $unidadeId, DadosDaUnidadeInput $dados): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $unidadeId, $dados): void {
            $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $unidadeId, $dados);
        });
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, int $unidadeId, DadosDaUnidadeInput $dados): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirUnidadesAlteraveis();
        $unidade = $this->unidades->buscarDaAssembleia($unidadeId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
        $linha = $this->leitor->lerLinha(
            [$dados->identificacao, $dados->bloco, $dados->situacao, $dados->fracao, $dados->responsavel, $dados->email, (string) $unidade->getTelefone()],
            $assembleia->getPeso(),
        );
        $chaves = LeitorDaListaDeUnidades::chavesDaLista($assembleia);
        $minhaChave = LinhaDeUnidade::chaveDe($unidade->getBloco(), $unidade->getIdentificacao());
        if ($linha->chave() !== $minhaChave && isset($chaves[$linha->chave()])) {
            throw new OperacaoInvalidaNaAssembleiaException('Esta unidade já está na lista.');
        }
        $unidade->corrigir($linha->identificacao, $linha->bloco, $linha->situacao, $linha->fracao, $linha->responsavel, $linha->email);
        $this->unidades->salvar($unidade, flush: true);
    }
}
