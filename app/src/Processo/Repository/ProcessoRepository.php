<?php

namespace App\Processo\Repository;

use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Processo>
 */
class ProcessoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Processo::class);
    }

    public function save(Processo $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Processo $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByNumeroProcesso(string $numeroProcesso): ?Processo
    {
        return $this->findOneBy(['numeroProcesso' => $numeroProcesso]);
    }

    /**
     * Busca tenant-safe por id — para resolver um processo existente sem depender do
     * filtro SQL do Doctrine, que NÃO se aplica a find() por PK (risco cross-tenant).
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?Processo
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Busca por número escopada explicitamente ao tenant (não depende do filtro de sessão).
     */
    public function findByNumeroProcessoDoTenant(string $numeroProcesso, Tenant $tenant): ?Processo
    {
        return $this->findOneBy(['numeroProcesso' => $numeroProcesso, 'tenant' => $tenant]);
    }

    /**
     * Busca processos do escritório por termo livre (número, classe, assunto ou tribunal),
     * para o autocomplete de "vincular processo existente". Filtra por tenant explicitamente
     * e permite excluir processos já vinculados.
     *
     * @param int[] $idsExcluir
     * @return Processo[]
     */
    public function buscarPorTermoDoTenant(string $termo, Tenant $tenant, array $idsExcluir = [], int $limite = 20): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $termo = trim($termo);
        if ($termo !== '') {
            $qb->andWhere(
                'p.numeroProcesso LIKE :termo'
                . ' OR LOWER(p.classeProcessual) LIKE LOWER(:termo)'
                . ' OR LOWER(p.assuntoProcessual) LIKE LOWER(:termo)'
                . ' OR LOWER(p.siglaTribunal) LIKE LOWER(:termo)'
            )->setParameter('termo', '%' . $termo . '%');
        }

        if ($idsExcluir !== []) {
            $qb->andWhere('p.id NOT IN (:excluir)')
               ->setParameter('excluir', $idsExcluir);
        }

        return $qb->orderBy('p.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Processo[]
     */
    public function findByFilters(array $filters): array
    {
        $qb = $this->createQueryBuilder('p');

        if (!empty($filters['numero_processo'])) {
            $qb->andWhere('p.numeroProcesso LIKE :numero')
               ->setParameter('numero', '%' . $filters['numero_processo'] . '%');
        }

        if (!empty($filters['tribunal'])) {
            $qb->andWhere('p.siglaTribunal = :tribunal')
               ->setParameter('tribunal', $filters['tribunal']);
        }

        if (!empty($filters['classe'])) {
            $qb->andWhere('p.classeProcessual LIKE :classe')
               ->setParameter('classe', '%' . $filters['classe'] . '%');
        }

        if (!empty($filters['assunto'])) {
            $qb->andWhere('p.assuntoProcessual LIKE :assunto')
               ->setParameter('assunto', '%' . $filters['assunto'] . '%');
        }

        if (!empty($filters['situacao'])) {
            $qb->andWhere('p.situacaoProcesso = :situacao')
               ->setParameter('situacao', $filters['situacao']);
        }

        return $qb->orderBy('p.id', 'DESC')->getQuery()->getResult();
    }

    /**
     * @return array<string, string>
     */
    public function findAllTribunais(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.siglaTribunal')
            ->where('p.siglaTribunal IS NOT NULL')
            ->andWhere('p.siglaTribunal != :empty')
            ->setParameter('empty', '')
            ->orderBy('p.siglaTribunal', 'ASC')
            ->getQuery()
            ->getResult();

        $tribunais = [];
        foreach ($result as $row) {
            $tribunais[$row['siglaTribunal']] = $row['siglaTribunal'];
        }
        return $tribunais;
    }

    /**
     * @return array<string, string>
     */
    public function findAllClasses(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.classeProcessual')
            ->where('p.classeProcessual IS NOT NULL')
            ->andWhere('p.classeProcessual != :empty')
            ->setParameter('empty', '')
            ->orderBy('p.classeProcessual', 'ASC')
            ->getQuery()
            ->getResult();

        $classes = [];
        foreach ($result as $row) {
            $classes[$row['classeProcessual']] = $row['classeProcessual'];
        }
        return $classes;
    }

    /**
     * @return array<string, string>
     */
    public function findAllAssuntos(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.assuntoProcessual')
            ->where('p.assuntoProcessual IS NOT NULL')
            ->andWhere('p.assuntoProcessual != :empty')
            ->setParameter('empty', '')
            ->orderBy('p.assuntoProcessual', 'ASC')
            ->getQuery()
            ->getResult();

        $assuntos = [];
        foreach ($result as $row) {
            $assuntos[$row['assuntoProcessual']] = $row['assuntoProcessual'];
        }
        return $assuntos;
    }

    /**
     * @return array<string, string>
     */
    public function findAllNumerosProcesso(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('DISTINCT p.numeroProcesso')
            ->where('p.numeroProcesso IS NOT NULL')
            ->andWhere('p.numeroProcesso != :empty')
            ->setParameter('empty', '')
            ->orderBy('p.numeroProcesso', 'ASC')
            ->getQuery()
            ->getResult();

        $numeros = [];
        foreach ($result as $row) {
            $numeros[$row['numeroProcesso']] = $row['numeroProcesso'];
        }
        return $numeros;
    }
}
