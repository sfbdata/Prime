<?php

declare(strict_types=1);

namespace App\Djen\Repository;

use App\Djen\Entity\PublicacaoDoDiarioDaOab;
use App\Djen\Enum\TipoDeAtoDoDiario;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicacaoDoDiarioDaOab>
 */
class PublicacaoDoDiarioDaOabRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicacaoDoDiarioDaOab::class);
    }

    public function salvar(PublicacaoDoDiarioDaOab $publicacao): void
    {
        $this->getEntityManager()->persist($publicacao);
        $this->getEntityManager()->flush();
    }

    /**
     * Publicações de quem registrou, da mais recente para a mais antiga.
     *
     * @return list<PublicacaoDoDiarioDaOab>
     */
    public function listarDoUsuario(Tenant $tenant, User $usuario, ?TipoDeAtoDoDiario $tipo, string $busca): array
    {
        $consulta = $this->doUsuario($tenant, $usuario)->orderBy('d.dataPublicacao', 'DESC')->addOrderBy('d.id', 'DESC');
        if ($tipo !== null) {
            $consulta->andWhere('d.tipo = :tipo')->setParameter('tipo', $tipo);
        }
        $busca = trim($busca);
        if ($busca !== '') {
            $consulta->andWhere('LOWER(d.numeroProcesso) LIKE :busca OR LOWER(d.ato) LIKE :busca OR LOWER(d.texto) LIKE :busca')
                ->setParameter('busca', '%' . mb_strtolower(addcslashes($busca, '%_\\')) . '%');
        }

        return $consulta->getQuery()->getResult();
    }

    public function contarNaoLidasDoUsuario(Tenant $tenant, User $usuario): int
    {
        return (int) $this->doUsuario($tenant, $usuario)->select('COUNT(d.id)')->andWhere('d.lidaEm IS NULL')->getQuery()->getSingleScalarResult();
    }

    public function contarLidasDoUsuario(Tenant $tenant, User $usuario): int
    {
        return (int) $this->doUsuario($tenant, $usuario)->select('COUNT(d.id)')->andWhere('d.lidaEm IS NOT NULL')->getQuery()->getSingleScalarResult();
    }

    /**
     * Marca como lidas (ou volta para não lidas) as publicações DO USUÁRIO entre os ids; id alheio é ignorado.
     *
     * @param list<int> $ids
     */
    public function atualizarLeitura(Tenant $tenant, User $usuario, array $ids, ?\DateTimeImmutable $lidaEm): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->atualizarDoUsuario($tenant, $usuario, 'd.lidaEm', $lidaEm)->andWhere('d.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->execute();
    }

    /** @param list<int> $ids */
    public function marcarExcluidas(Tenant $tenant, User $usuario, array $ids, \DateTimeImmutable $em): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->atualizarDoUsuario($tenant, $usuario, 'd.excluidaEm', $em)->andWhere('d.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->execute();
    }

    public function marcarLidasComoExcluidas(Tenant $tenant, User $usuario, \DateTimeImmutable $em): int
    {
        return (int) $this->atualizarDoUsuario($tenant, $usuario, 'd.excluidaEm', $em)->andWhere('d.lidaEm IS NOT NULL')->getQuery()->execute();
    }

    private function atualizarDoUsuario(Tenant $tenant, User $usuario, string $campo, ?\DateTimeImmutable $valor): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->update()
            ->set($campo, ':valor')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.usuario = :usuario')
            ->andWhere('d.excluidaEm IS NULL')
            ->setParameter('valor', $valor)
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario);
    }

    private function doUsuario(Tenant $tenant, User $usuario): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.usuario = :usuario')
            ->andWhere('d.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario);
    }
}
