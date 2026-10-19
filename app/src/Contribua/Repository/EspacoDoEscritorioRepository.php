<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EspacoDoEscritorio>
 */
// Não-final: os testes de UseCase mockam o repositório.
class EspacoDoEscritorioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EspacoDoEscritorio::class);
    }

    public function salvar(EspacoDoEscritorio $espaco, bool $flush = false): void
    {
        $this->getEntityManager()->persist($espaco);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O espaço do escritório; sem linha, cria uma no plano dado (sem persistir: quem salva é o UseCase). */
    public function doTenantOuNovo(int $tenantId, Plano $planoInicial): EspacoDoEscritorio
    {
        return $this->doTenant($tenantId)
            ?? new EspacoDoEscritorio($this->getEntityManager()->getReference(Tenant::class, $tenantId), $planoInicial);
    }

    /**
     * O espaço do escritório, ou null se ele nunca saiu do Free nem recebeu limite do Master.
     *
     * Por id de tenant e não por entidade: o decorador de armazenamento só conhece o id que vem na
     * chave do arquivo. O Master age sobre outro escritório, por isso o filtro é explícito aqui e
     * não depende do TenantFilter da sessão.
     */
    public function doTenant(int $tenantId): ?EspacoDoEscritorio
    {
        return $this->createQueryBuilder('e')
            ->join('e.plano', 'p')->addSelect('p')
            ->andWhere('IDENTITY(e.tenant) = :tenant')
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
