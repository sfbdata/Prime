<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\LinhaDeUnidade;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\LeitorDaListaDeUnidades;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Edita o cadastro de uma unidade (menu ⋮ "Editar" da lista de links), enquanto nenhuma pauta foi aberta: a mesma regra
 * da importação e da remoção. Valida com as regras do leitor da lista (fração, e-mail, telefone, tamanhos) e recusa
 * a troca que repete outra unidade (bloco e identificação). O link da unidade continua o mesmo.
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

    /** @param array{identificacao: string, bloco: string, situacao: string, fracao: string, responsavel: string, email: string, telefone: string} $campos */
    public function executar(Tenant $tenant, int $assembleiaId, int $unidadeId, array $campos): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $unidadeId, $campos): void {
            $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $unidadeId, $campos);
        });
    }

    /** @param array{identificacao: string, bloco: string, situacao: string, fracao: string, responsavel: string, email: string, telefone: string} $campos */
    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, int $unidadeId, array $campos): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirUnidadesAlteraveis();
        $unidade = $this->unidades->buscarDaAssembleia($unidadeId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();

        if ('' === trim($campos['identificacao'])) {
            throw new OperacaoInvalidaNaAssembleiaException('Informe a unidade.');
        }
        $linha = $this->leitor->lerUmaLinha([
            $campos['identificacao'], $campos['bloco'], $campos['situacao'], $campos['fracao'],
            $campos['responsavel'], $campos['email'], $campos['telefone'], '',
        ], $assembleia->getPeso());
        foreach ($assembleia->getUnidades() as $outra) {
            if ($outra !== $unidade && LinhaDeUnidade::chaveDe($outra->getBloco(), $outra->getIdentificacao()) === $linha->chave()) {
                throw new OperacaoInvalidaNaAssembleiaException('Já existe outra unidade com essa identificação e esse bloco.');
            }
        }
        $unidade->editarCadastro($linha);
        $this->unidades->salvar($unidade, flush: true);
    }
}
