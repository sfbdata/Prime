<?php
declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\UserTenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;

/**
 * A regra única de quem pode deixar de ser primário (spec perfis-master §2 D3/D4, §5 E1).
 *
 * Primário = vínculo ativo cujo perfil é o de sistema (isSystem). Duas travas, nesta ordem:
 *  1. o primário TITULAR (Tenant.criadoPor, quem abriu a conta) nunca é rebaixado;
 *  2. o escritório nunca fica sem primário ativo.
 *
 * Mora num serviço, e não dentro de um UseCase, porque há duas portas que trocam o perfil de
 * um vínculo: a remoção de sócio (RemoverPrimarioSocioUseCase) e a edição do colaborador
 * (TenantController::editUserRole). Regra copiada nas duas acabaria divergindo.
 */
final class RegraDosPrimarios
{
    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
    ) {}

    public function ehPrimario(UserTenant $vinculo): bool
    {
        return $vinculo->isActive() && $vinculo->getTenantRole()?->isSystem() === true;
    }

    public function ehTitular(UserTenant $vinculo): bool
    {
        $titular = $vinculo->getTenant()->getCriadoPor();
        if ($titular === null) {
            return false;
        }

        $usuario = $vinculo->getUser();
        if ($titular === $usuario) {
            return true;
        }

        // Proxy x entidade carregada: compara por id, mas só quando os dois têm id (sem id,
        // null === null daria "titular" para qualquer um).
        return $titular->getId() !== null && $titular->getId() === $usuario->getId();
    }

    /**
     * Lança se o vínculo (hoje primário) não puder deixar de sê-lo.
     *
     * Deve ser chamada ANTES do flush que troca o perfil: a contagem lê o banco, onde o vínculo
     * ainda está com o perfil de sistema.
     *
     * @throws OperacaoDePrimarioRecusada
     */
    public function garantirQuePodeDeixarDeSerPrimario(UserTenant $vinculo): void
    {
        if ($this->ehTitular($vinculo)) {
            throw new OperacaoDePrimarioRecusada(
                'O primário titular (quem abriu a conta) não pode deixar de ser primário.'
            );
        }

        if ($this->userTenantRepository->contarAdminsAtivos($vinculo->getTenant()) <= 1) {
            throw new OperacaoDePrimarioRecusada(
                'Este é o último primário do escritório. Promova outro primário antes de removê-lo.'
            );
        }
    }
}
