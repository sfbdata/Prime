<?php

declare(strict_types=1);

namespace App\Tenant\Repository;

use App\Entity\Auth\UserTenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Consultas sobre a equipe de um escritório (vínculos UserTenant) que nasceram no domínio Tenant.
 * O UserTenantRepository legado continua dono da entidade; aqui ficam só leituras novas.
 * Sem `final`: os testes de UseCase mockam esta classe.
 */
class EquipeDoEscritorioRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Quantos vínculos ativos tem cada um dos escritórios informados (chave = id do tenant).
     * Quem chama passa só os escritórios do próprio usuário: a contagem não abre a lista de pessoas.
     *
     * @param int[] $tenantIds
     *
     * @return array<int, int>
     */
    public function contarAtivosPorTenant(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $linhas = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(ut.tenant) AS tenantId, COUNT(ut.id) AS total')
            ->from(UserTenant::class, 'ut')
            ->andWhere('ut.tenant IN (:tenants)')
            ->andWhere('ut.isActive = true')
            ->setParameter('tenants', $tenantIds)
            ->groupBy('ut.tenant')
            ->getQuery()
            ->getArrayResult();

        $contagem = [];
        foreach ($linhas as $linha) {
            $contagem[(int) $linha['tenantId']] = (int) $linha['total'];
        }

        return $contagem;
    }
}
