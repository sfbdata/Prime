<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\TokenDaUnidade;

/**
 * "Gerar novo link": troca a semente da unidade e o link anterior deixa de valer (link perdido ou repassado a quem
 * não devia). A unidade volta a "ainda não recebeu" para o envio por e-mail. Vale a qualquer momento: a participação
 * já registrada continua da unidade.
 */
final class GerarNovoLinkDaUnidadeUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly TokenDaUnidade $tokens,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $unidadeId): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $unidade = $this->unidades->buscarDaAssembleia($unidadeId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
        $semente = $this->tokens->novaSemente();
        $unidade->trocarSemente($semente, $this->tokens->hash($this->tokens->token($semente)));
        $this->unidades->salvar($unidade, flush: true);
    }
}
