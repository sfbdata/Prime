<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * A equipe que o cadeado restringe: os vínculos ativos do escritório, de conta ativa, menos quem
 * configura (primário e Master), que nunca é restringido (K1). É a grade "Colaborador" do modal
 * (desenho L526, `U = usuarios().filter(!ehPrimario)`), em ordem de nome.
 */
final class EquipeDoCadeado
{
    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {
    }

    /** @return list<UserTenant> */
    public function doEscritorio(Tenant $tenant): array
    {
        return array_values(array_filter(
            $this->userTenantRepository->findAtivosComPerfilPorTenant($tenant),
            fn (UserTenant $v): bool => $v->getUser()->isActive()
                && !$this->regraDosPrimarios->ehPrimario($v)
                && !$this->regraDosPrimarios->ehMaster($v->getUser()),
        ));
    }

    /** "Cargo · Lotação", a segunda linha de cada pessoa na grade (desenho `[u.funcao, u.setor]`). */
    public static function funcao(UserTenant $vinculo): string
    {
        return implode(' · ', array_filter([
            $vinculo->getCargo()?->getNome(),
            $vinculo->getLotacao()?->getNome(),
        ], static fn (?string $p): bool => $p !== null && trim($p) !== ''));
    }
}
