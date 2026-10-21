<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Entity\Sugestao;
use App\Contribua\Repository\SugestaoRepository;
use App\Contribua\Service\VisaoDoMaster;
use App\Entity\Auth\User;

/**
 * O Master marca a sugestão de qualquer escritório como atendida (com resposta opcional para quem
 * enviou) ou a reabre. A autorização (`ROLE_SUPER_ADMIN`) é do controller.
 */
final class AtenderSugestaoUseCase
{
    public function __construct(
        private readonly SugestaoRepository $sugestoes,
        private readonly VisaoDoMaster $visaoDoMaster,
    ) {
    }

    /** @return bool atendida depois da troca, ou null se a sugestão não existe */
    public function executar(User $master, int $sugestaoId, ?string $resposta): ?bool
    {
        $sugestao = $this->visaoDoMaster->deTodosOsEscritorios(fn (): ?Sugestao => $this->sugestoes->find($sugestaoId));
        if (!$sugestao instanceof Sugestao) {
            return null;
        }

        $sugestao->alternarAtendimento($resposta, $master);
        $this->sugestoes->salvar($sugestao, flush: true);

        return $sugestao->isAtendida();
    }
}
