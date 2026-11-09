<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AbonoEmLoteOutput;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Service\AbonoDeJustificativa;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Repository\UserTenantRepository;

/**
 * "Abonar selecionadas" da aba Equipe da /ponto (spec `docs/specs/ponto-desenho-0811.md`, E5, P57; desenho 16 L217):
 * o gestor marca as justificativas da lista e abona todas de uma vez.
 *
 * Cada id passa pelas mesmas portas da ficha do colaborador, uma a uma:
 * - só do escritório da sessão e de quem tem vínculo ativo nele (id de outro escritório ou que não existe não abona);
 * - a própria só com o P43 a (`AnaliseDaPropriaJustificativa`: o primário e o Master);
 * - o abono em si é o da ficha (`AbonoDeJustificativa`), que ainda pode recusar (já analisada, batida já registrada,
 *   horário que não chegou).
 * Uma recusa não impede as outras. Quem chama já conferiu que quem pede gere a equipe (`GestaoDoPonto::veEquipe`).
 */
final class AbonarJustificativasDaEquipeUseCase
{
    public const NAO_ENCONTRADA = 'Justificativa não encontrada neste escritório.';

    public function __construct(
        private readonly JustificativaPontoRepository $justificativas,
        private readonly UserTenantRepository $vinculos,
        private readonly AnaliseDaPropriaJustificativa $analiseDaPropria,
        private readonly AbonoDeJustificativa $abono,
    ) {
    }

    /** @param list<int> $ids */
    public function executar(User $analista, Tenant $escritorio, array $ids): AbonoEmLoteOutput
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        $achadas = $this->justificativas->doEscritorioPorIds($ids, $escritorio);

        $abonadas = 0;
        $recusas = [];
        foreach ($ids as $id) {
            $recusa = $this->abonarUma($achadas[$id] ?? null, $analista, $escritorio);
            if ($recusa === null) {
                ++$abonadas;
            } else {
                $recusas[] = $recusa;
            }
        }

        return new AbonoEmLoteOutput($abonadas, $recusas);
    }

    /** Null quando abonou; senão, a recusa já com a pessoa e o dia. */
    private function abonarUma(?JustificativaPonto $justificativa, User $analista, Tenant $escritorio): ?string
    {
        $dono = $justificativa?->getUser();
        if ($justificativa === null || $dono === null || !$this->vinculos->existeVinculoAtivo($dono, $escritorio)) {
            return self::NAO_ENCONTRADA;
        }
        $recusa = $this->analiseDaPropria->permite($analista, $dono, $escritorio)
            ? $this->abono->abonar($justificativa, $escritorio, $analista)
            : AnaliseDaPropriaJustificativa::RECUSA;

        return $recusa === null ? null : sprintf(
            '%s (%s): %s',
            $dono->getFullName() ?? $dono->getEmail(),
            $justificativa->getData()?->format('d/m/Y'),
            $recusa,
        );
    }
}
