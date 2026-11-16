<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\DadosDaUnidadeInput;
use App\Voto\DTO\UnidadeParaEdicao;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\LeituraDaAssembleia;

/** A tela de edição de uma unidade: os campos atuais, só se a unidade for desta assembleia e deste escritório. */
final class ObterUnidadeParaEdicaoUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly LeituraDaAssembleia $leitura,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $unidadeId): UnidadeParaEdicao
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $unidade = $this->unidades->buscarDaAssembleia($unidadeId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
        $fracao = $unidade->getFracao();

        return new UnidadeParaEdicao($this->leitura->montar($assembleia), $unidadeId, new DadosDaUnidadeInput(
            identificacao: $unidade->getIdentificacao(),
            bloco: $unidade->getBloco(),
            situacao: (string) $unidade->getSituacao(),
            fracao: null === $fracao ? '' : str_replace('.', ',', rtrim(rtrim($fracao, '0'), '.')),
            responsavel: (string) $unidade->getResponsavel(),
            email: (string) $unidade->getEmail(),
        ));
    }
}
