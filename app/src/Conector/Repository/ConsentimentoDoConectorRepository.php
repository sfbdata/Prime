<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Consentimentos (as "conexões" de I.A.). Toda busca leva o escritório explícito, sem depender do `TenantFilter`: o
 * `/oauth/authorize` e o Meu Perfil podem rodar sem o filtro ligado.
 *
 * @extends ServiceEntityRepository<ConsentimentoDoConector>
 */
class ConsentimentoDoConectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsentimentoDoConector::class);
    }

    public function salvar(ConsentimentoDoConector $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** A conexão ativa da pessoa com este app neste escritório (há no máximo uma). */
    public function buscarAtivo(User $user, Tenant $tenant, ClienteDoConector $cliente): ?ConsentimentoDoConector
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.user = :user')
            ->andWhere('c.cliente = :cliente')
            ->andWhere('c.revogadoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('user', $user)
            ->setParameter('cliente', $cliente)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As conexões ativas da pessoa neste escritório, da mais recente para a mais antiga.
     *
     * @return list<ConsentimentoDoConector>
     */
    public function listarAtivosDaPessoa(User $user, Tenant $tenant): array
    {
        /** @var list<ConsentimentoDoConector> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.user = :user')
            ->andWhere('c.revogadoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('user', $user)
            ->orderBy('c.concedidoEm', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
