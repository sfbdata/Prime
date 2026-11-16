<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AvaliacaoNaLista;
use App\Voto\DTO\AvaliacoesDaAssembleia;
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\SemPapelNaAvaliacaoException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\OcorrenciaDaUnidadeRepository;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\LeituraDaAssembleia;

/**
 * A aba "Avaliações" (spec voto-avaliacoes-do-conselho-1611, seções 3 e 4): só quem decide a avaliação a vê
 * ({@see AcessoAAssembleia::podeVerAvaliacoes()}: o conselho e a Administração, ou só a Administração sem equipe própria).
 * Lista só as ocorrências com avaliação (a da pessoa apta e a da importação não têm), as pendentes primeiro e depois a
 * mais recente. Assembleia de outro escritório dá 404.
 */
final class ObterAvaliacoesDaAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly OcorrenciaDaUnidadeRepository $ocorrencias,
        private readonly AcessoAAssembleia $acesso,
        private readonly EncerrarPautaPorPrazoVencidoUseCase $encerrarVencidas,
        private readonly LeituraDaAssembleia $leitura,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, User $ator, ?\DateTimeImmutable $agora = null): AvaliacoesDaAssembleia
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        if (!$this->acesso->podeVerAvaliacoes($ator, $assembleia)) {
            throw new SemPapelNaAvaliacaoException();
        }
        $agora ??= new \DateTimeImmutable();
        $this->encerrarVencidas->executar($assembleia, $agora);

        $avaliacoes = array_values(array_filter(
            $this->ocorrencias->listarDaAssembleia($assembleia),
            static fn (OcorrenciaDaUnidade $o): bool => null !== $o->getAvaliacao(),
        ));
        // A consulta já vem da mais recente para a mais antiga; o sort do PHP é estável, então só levanta as pendentes.
        usort($avaliacoes, static fn (OcorrenciaDaUnidade $x, OcorrenciaDaUnidade $y): int => (int) $y->pendente() <=> (int) $x->pendente());

        return new AvaliacoesDaAssembleia(
            assembleia: $this->leitura->montar($assembleia),
            avaliacoes: array_map(AvaliacaoNaLista::deOcorrencia(...), $avaliacoes),
            podeTornarApto: !$assembleia->algumaPautaEncerrada($agora),
        );
    }
}
