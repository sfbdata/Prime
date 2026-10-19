<?php

declare(strict_types=1);

namespace App\Tenant\Repository;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tenant\Enum\SituacaoNaEquipe;
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

    /**
     * A equipe da conta: vínculos ativos e os que estão em análise ou suspensos (inativos por causa da situação).
     * Os inativos de legado (situação aprovado e isActive falso) ficam fora. Primários primeiro, depois por nome.
     *
     * @return list<UserTenant>
     */
    public function membrosDaEquipe(Tenant $tenant): array
    {
        /** @var list<UserTenant> $lista */
        $lista = $this->entityManager->createQueryBuilder()
            ->select('ut', 'u', 'r', 'c')
            ->from(UserTenant::class, 'ut')
            ->join('ut.user', 'u')
            ->leftJoin('ut.tenantRole', 'r')
            ->leftJoin('ut.cargo', 'c')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true OR ut.situacao <> :aprovado')
            ->setParameter('tenant', $tenant)
            ->setParameter('aprovado', SituacaoNaEquipe::Aprovado->value)
            ->addSelect('CASE WHEN r.isSystem = true THEN 0 ELSE 1 END AS HIDDEN ordem')
            ->orderBy('ordem', 'ASC')
            ->addOrderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        return $lista;
    }

    /**
     * Um vínculo da equipe deste escritório, com o mesmo recorte da lista (ativo, ou inativo por causa da situação);
     * de outro escritório ou inativo de legado, null (a rota responde 404).
     */
    public function membroDoEscritorio(int $id, Tenant $tenant): ?UserTenant
    {
        $vinculo = $this->entityManager->getRepository(UserTenant::class)->findOneBy(['id' => $id, 'tenant' => $tenant]);
        if ($vinculo === null || (!$vinculo->isActive() && $vinculo->getSituacao() === SituacaoNaEquipe::Aprovado)) {
            return null;
        }

        return $vinculo;
    }

    /** Situação da pessoa neste escritório, se ela tem vínculo (para o convite não passar por cima dela). */
    public function situacaoDe(\App\Entity\Auth\User $usuario, Tenant $tenant): ?SituacaoNaEquipe
    {
        return $this->entityManager->getRepository(UserTenant::class)->findOneBy(['user' => $usuario, 'tenant' => $tenant])?->getSituacao();
    }

    /**
     * @template T
     *
     * @param callable(): T $operacao
     *
     * @return T
     */
    public function emTransacao(callable $operacao): mixed
    {
        return $this->entityManager->wrapInTransaction($operacao);
    }

    /**
     * Mensagens de acesso dos vínculos da pessoa bloqueados pela situação (em análise ou suspenso) em escritórios
     * ativos: a tela de seleção explica por que o escritório não aparece na lista.
     *
     * @return list<string>
     */
    public function mensagensDeAcessoBloqueado(\App\Entity\Auth\User $usuario): array
    {
        /** @var list<UserTenant> $vinculos */
        $vinculos = $this->entityManager->createQueryBuilder()
            ->select('ut', 't')
            ->from(UserTenant::class, 'ut')
            ->join('ut.tenant', 't')
            ->andWhere('ut.user = :usuario')
            ->andWhere('ut.isActive = false')
            ->andWhere('ut.situacao <> :aprovado')
            ->andWhere('t.isActive = true')
            ->setParameter('usuario', $usuario)
            ->setParameter('aprovado', SituacaoNaEquipe::Aprovado->value)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (UserTenant $v): string => $v->getSituacao()->mensagemDeAcesso((string) $v->getTenant()->getName()), $vinculos);
    }

    public function gravar(): void
    {
        $this->entityManager->flush();
    }
}
