<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\MinhaContaOutput;
use App\Tenant\Repository\EquipeDoEscritorioRepository;

/**
 * Contas (escritórios) a que o usuário pertence, para a aba "Trocar conta" (desenho
 * "07 - Meu Perfil" e bj-contas.js, VS 18.10.26). Isolamento: parte dos vínculos ativos
 * da própria pessoa; nenhum escritório alheio entra na lista nem na contagem.
 * O "primário" exibido é o titular (Tenant.criadoPor), quem abriu a conta.
 */
final class ListarMinhasContasUseCase
{
    public function __construct(
        private readonly UserTenantRepository $userTenantRepository,
        private readonly EquipeDoEscritorioRepository $equipeRepository,
    ) {
    }

    /**
     * @return MinhaContaOutput[]
     */
    public function executar(User $usuario, ?int $tenantAtualId): array
    {
        $vinculos = $this->userTenantRepository->findActiveByUser($usuario);
        $contagem = $this->equipeRepository->contarAtivosPorTenant(
            array_map(static fn (UserTenant $v): int => (int) $v->getTenant()->getId(), $vinculos)
        );

        $contas = array_map(
            fn (UserTenant $v): MinhaContaOutput => $this->montarConta($v, $usuario, $contagem, $tenantAtualId),
            $vinculos
        );
        usort($contas, static fn (MinhaContaOutput $a, MinhaContaOutput $b): int => strcasecmp($a->nome, $b->nome));

        return $contas;
    }

    /**
     * @param array<int, int> $contagem
     */
    private function montarConta(UserTenant $vinculo, User $usuario, array $contagem, ?int $tenantAtualId): MinhaContaOutput
    {
        $tenant  = $vinculo->getTenant();
        $id      = (int) $tenant->getId();
        $titular = $tenant->getCriadoPor();

        return new MinhaContaOutput(
            id: $id,
            nome: (string) $tenant->getName(),
            usuarios: $contagem[$id] ?? 1,
            primarioNome: $titular?->getFullName(),
            atual: $id === $tenantAtualId,
            souTitular: $titular !== null && $titular->getId() === $usuario->getId(),
        );
    }
}
