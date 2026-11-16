<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\DadosDaUnidadeInput;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\LeitorDaListaDeUnidades;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\Service\TokenDaUnidade;

/**
 * O gestor adiciona uma unidade à lista, enquanto nenhuma pauta foi aberta. Mesma validação da planilha (unidade
 * obrigatória, fração maior que zero e obrigatória no peso por fração, e-mail válido) e mesma regra de duplicidade
 * (bloco e unidade). Nasce com o próprio link. Sem telefone: ele só entra pela importação.
 */
final class AdicionarUnidadeUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly LeitorDaListaDeUnidades $leitor,
        private readonly TokenDaUnidade $tokens,
        private readonly SerializacaoDaConfiguracao $serializacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, DadosDaUnidadeInput $dados): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $dados): void {
            $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $dados);
        });
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, DadosDaUnidadeInput $dados): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirUnidadesAlteraveis();
        $linha = $this->leitor->lerLinha(
            [$dados->identificacao, $dados->bloco, $dados->situacao, $dados->fracao, $dados->responsavel, $dados->email, ''],
            $assembleia->getPeso(),
        );
        if (isset(LeitorDaListaDeUnidades::chavesDaLista($assembleia)[$linha->chave()])) {
            throw new OperacaoInvalidaNaAssembleiaException('Esta unidade já está na lista.');
        }
        $semente = $this->tokens->novaSemente();
        $this->unidades->salvar(new UnidadeDaAssembleia(
            assembleia: $assembleia,
            identificacao: $linha->identificacao,
            semente: $semente,
            tokenHash: $this->tokens->hash($this->tokens->token($semente)),
            bloco: $linha->bloco,
            situacao: $linha->situacao,
            fracao: $linha->fracao,
            responsavel: $linha->responsavel,
            email: $linha->email,
        ), flush: true);
    }
}
