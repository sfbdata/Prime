<?php

declare(strict_types=1);

namespace App\Blog\Repository;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Artigos dos blogs. Sem filtro de tenant de propósito: o artigo é conteúdo GLOBAL da plataforma, escrito só pelo
 * Master (ver {@see ArtigoDoBlog}); nenhum escritório lê ou grava aqui.
 *
 * @extends ServiceEntityRepository<ArtigoDoBlog>
 */
class ArtigoDoBlogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArtigoDoBlog::class);
    }

    public function salvar(ArtigoDoBlog $artigo, bool $flush = false): void
    {
        $this->getEntityManager()->persist($artigo);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(ArtigoDoBlog $artigo, bool $flush = false): void
    {
        $this->getEntityManager()->remove($artigo);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function porId(int $id): ?ArtigoDoBlog
    {
        return $this->find($id);
    }

    public function porSlug(string $slug): ?ArtigoDoBlog
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** O slug já é de outro artigo? `excetoId` é o próprio artigo, na edição. */
    public function existeSlug(string $slug, ?int $excetoId = null): bool
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.slug = :slug')
            ->setParameter('slug', $slug);
        if ($excetoId !== null) {
            $qb->andWhere('a.id <> :excetoId')->setParameter('excetoId', $excetoId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Todos os slugs cadastrados, em ordem alfabética (validação dos relacionados).
     *
     * @return list<string>
     */
    public function slugsExistentes(): array
    {
        $linhas = $this->createQueryBuilder('a')
            ->select('a.slug')
            ->orderBy('a.slug', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map('strval', $linhas));
    }

    /**
     * Lista da seção "Artigos dos blogs" (P2): busca no título e no slug, filtros opcionais de situação e de blog,
     * os alterados por último primeiro.
     *
     * @return Paginator<ArtigoDoBlog>
     */
    public function listar(?string $busca, ?SituacaoDoArtigo $situacao, ?BlogsDoArtigo $blogs, int $pagina = 1, int $porPagina = 50): Paginator
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.alteradoEm', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult((max(1, $pagina) - 1) * $porPagina)
            ->setMaxResults($porPagina);
        $this->aplicarBusca($qb, $busca);
        if ($situacao !== null) {
            $qb->andWhere('a.situacao = :situacao')->setParameter('situacao', $situacao);
        }
        if ($blogs !== null) {
            $qb->andWhere('a.blogs = :blogs')->setParameter('blogs', $blogs);
        }

        return new Paginator($qb);
    }

    private function aplicarBusca(QueryBuilder $qb, ?string $busca): void
    {
        $busca = trim((string) $busca);
        if ($busca === '') {
            return;
        }

        $termo = '%' . addcslashes(mb_strtolower($busca), '%_\\') . '%';
        $qb->andWhere('LOWER(a.titulo) LIKE :busca OR LOWER(a.slug) LIKE :busca')->setParameter('busca', $termo);
    }
}
