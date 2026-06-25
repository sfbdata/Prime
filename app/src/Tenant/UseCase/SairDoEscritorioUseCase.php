<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saída voluntária de um colaborador de um escritório.
 *
 * Diferente de DemitirFuncionarioUseCase (ação de um admin sobre outro):
 * aqui o próprio usuário deixa o vínculo. Regra crítica (RN06): o último
 * administrador ativo não pode sair e deixar o escritório órfão.
 */
final class SairDoEscritorioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserTenantRepository $userTenantRepository,
    ) {}

    public function executar(User $usuario, Tenant $tenant): void
    {
        $vinculo = $this->userTenantRepository->findAtivoPorUserETenant($usuario, $tenant);
        if ($vinculo === null) {
            throw new \InvalidArgumentException('Você não possui vínculo ativo com este escritório.');
        }

        if ($this->ehUltimoAdmin($vinculo, $tenant)) {
            throw new \InvalidArgumentException(
                'Você é o único administrador deste escritório. '
                . 'Transfira a titularidade ou exclua o escritório antes de sair.'
            );
        }

        $vinculo->sair();
        $this->em->flush();
    }

    private function ehUltimoAdmin(UserTenant $vinculo, Tenant $tenant): bool
    {
        $role = $vinculo->getTenantRole();
        if ($role === null || !$role->isSystem()) {
            return false;
        }

        return $this->userTenantRepository->contarAdminsAtivos($tenant) <= 1;
    }
}
