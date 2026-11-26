<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaNoPainel;
use App\Voto\DTO\OcorrenciaNaVotacao;
use App\Voto\Entity\OcorrenciaDaVotacao;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\SemPapelNaAvaliacaoException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OcorrenciaDaVotacaoRepository;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\LeituraDaAssembleia;

/**
 * A lista "Ocorrências" da gestão da assembleia: só para quem a gere (nível Editar; {@see AcessoAAssembleia::podeRegistrarOcorrencia()}
 * é a mesma regra). Assembleia de outro escritório dá 404; quem não gere, {@see SemPapelNaAvaliacaoException} (403).
 */
final class ListarOcorrenciasDaVotacaoUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly OcorrenciaDaVotacaoRepository $ocorrencias,
        private readonly AcessoAAssembleia $acesso,
        private readonly LeituraDaAssembleia $leitura,
    ) {
    }

    /** @return array{assembleia: AssembleiaNoPainel, ocorrencias: list<OcorrenciaNaVotacao>} */
    public function executar(Tenant $tenant, int $assembleiaId, User $ator): array
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        if (!$this->acesso->podeRegistrarOcorrencia($ator, $assembleia)) {
            throw new SemPapelNaAvaliacaoException();
        }

        return [
            'assembleia' => $this->leitura->montar($assembleia),
            'ocorrencias' => array_map(
                static fn (OcorrenciaDaVotacao $o): OcorrenciaNaVotacao => OcorrenciaNaVotacao::deOcorrencia($o),
                $this->ocorrencias->listarDaAssembleia($assembleia),
            ),
        ];
    }
}
