<?php

declare(strict_types=1);

namespace App\Processo\Repository;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotaTecnica>
 */
// Não-final: espelha ProcessoRepository/PublicacaoDjenRepository e permite substituição por mock
// nos testes unitários dos UseCases (que não sobem o contêiner).
class NotaTecnicaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotaTecnica::class);
    }

    public function salvar(NotaTecnica $nota, bool $flush = false): void
    {
        $this->getEntityManager()->persist($nota);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(NotaTecnica $nota, bool $flush = false): void
    {
        $this->getEntityManager()->remove($nota);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca tenant-safe por id — o filtro SQL do Doctrine NÃO se aplica a find() por PK, então o
     * id vindo da URL só vira entidade se for do escritório da sessão.
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?NotaTecnica
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Todas as notas de um processo (inclusive as penduradas em publicações), mais recente
     * primeiro — a nota recém-escrita é a que interessa ler. Uso exclusivo de exibição.
     *
     * @return NotaTecnica[]
     */
    public function listarPorProcesso(Processo $processo, Tenant $tenant, int $limite = 100): array
    {
        // Fetch-join do autor e da publicação: o Output lê os dois, e sem isso cada nota da tela
        // disparava duas consultas preguiçosas.
        return $this->createQueryBuilder('n')
            ->addSelect('a', 'pub')
            ->leftJoin('n.autor', 'a')
            ->leftJoin('n.publicacaoDjen', 'pub')
            ->andWhere('n.processo = :processo')
            ->andWhere('n.tenant = :tenant')
            ->setParameter('processo', $processo)
            ->setParameter('tenant', $tenant)
            ->orderBy('n.criadaEm', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Só as notas penduradas NESTA movimentação do Push Processual, mais recente primeiro.
     *
     * @return NotaTecnica[]
     */
    public function listarPorPublicacao(PublicacaoDjen $publicacao, Tenant $tenant, int $limite = 100): array
    {
        // Fetch-join do autor e da publicação: o Output lê os dois, e sem isso cada nota da tela
        // disparava duas consultas preguiçosas.
        return $this->createQueryBuilder('n')
            ->addSelect('a', 'pub')
            ->leftJoin('n.autor', 'a')
            ->leftJoin('n.publicacaoDjen', 'pub')
            ->andWhere('n.publicacaoDjen = :publicacao')
            ->andWhere('n.tenant = :tenant')
            ->setParameter('publicacao', $publicacao)
            ->setParameter('tenant', $tenant)
            ->orderBy('n.criadaEm', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
