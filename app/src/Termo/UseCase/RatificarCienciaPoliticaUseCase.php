<?php

declare(strict_types=1);

namespace App\Termo\UseCase;

use App\Entity\Auth\User;
use App\Legal\PoliticaPrivacidadeVigente;
use App\Termo\Entity\CienciaPolitica;
use App\Termo\Enum\OrigemDoAceite;
use App\Termo\Repository\CienciaPoliticaRepository;
use Psr\Clock\ClockInterface;

/**
 * D2b da spec `cadastro-senha-no-link.md`: a conta confirmada pelo código do "Esqueceu a senha?" ficou só com a
 * ciência da Política do cadastro, que não vale (cadastro_nao_ratificado). O gate dos Termos pede a Política junto
 * e, no aceite, grava a ciência como `reaceite`. Para os outros, nada muda: o gate continua só dos Termos.
 */
final class RatificarCienciaPoliticaUseCase
{
    public function __construct(
        private readonly CienciaPoliticaRepository $cienciaPoliticaRepository,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function precisa(User $user): bool
    {
        return $this->cienciaPoliticaRepository->precisaRatificar($user, PoliticaPrivacidadeVigente::VERSAO);
    }

    /** Grava a ciência `reaceite` só para quem precisa; devolve se gravou. */
    public function executar(User $user, string $ip, ?string $userAgent): bool
    {
        if (!$this->precisa($user)) {
            return false;
        }

        $this->cienciaPoliticaRepository->salvar(new CienciaPolitica(
            user: $user,
            versao: PoliticaPrivacidadeVigente::VERSAO,
            ip: $ip,
            userAgent: $userAgent,
            cienteEm: $this->relogio->now(),
            origem: OrigemDoAceite::Reaceite,
        ), flush: true);

        return true;
    }
}
