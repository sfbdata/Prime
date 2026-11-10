<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Repository\SaidaDaEquipeRepository;

/**
 * A pessoa é ou foi do escritório (spec docs/specs/ponto-desenho-0811.md, E16, decisão 28): é o que o Master precisa
 * para exportar a folha de outra pessoa. Vale qualquer um dos três rastros, do mais barato ao mais caro:
 * - o vínculo, ativo ou não (suspenso, em análise);
 * - a saída da equipe gravada: quem saiu perde o vínculo (`RemoverColaboradorDoEscritorioUseCase` o apaga);
 * - uma batida gravada neste escritório: quem saiu antes de 19/10/2026 não tem a saída gravada, mas as batidas ficam.
 */
final class PassagemPeloEscritorio
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly SaidaDaEquipeRepository $saidas,
        private readonly RegistroPontoRepository $registros,
    ) {
    }

    public function houve(User $pessoa, Tenant $tenant): bool
    {
        return $this->vinculos->findPorUserETenant($pessoa, $tenant) !== null
            || $this->saidas->existeDe($pessoa, $tenant)
            || $this->registros->existeDoUsuarioNoEscritorio($pessoa, $tenant);
    }
}
