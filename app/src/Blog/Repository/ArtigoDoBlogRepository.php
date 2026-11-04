<?php

declare(strict_types=1);

namespace App\Blog\Repository;

use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Service\Marcadores;
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
     * Os artigos no ar, por slug: o pacote do builder leva todos eles (spec painel-artigos-blogs, seção 6.1).
     *
     * @return list<ArtigoDoBlog>
     */
    public function publicados(): array
    {
        /** @var list<ArtigoDoBlog> */
        return $this->createQueryBuilder('a')
            ->andWhere('a.situacao = :publicado')
            ->setParameter('publicado', SituacaoDoArtigo::Publicado)
            ->orderBy('a.slug', 'ASC')
            ->getQuery()
            ->getResult();
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

    /**
     * Título de cada slug pedido (os que existem), para mostrar os relacionados pelo nome.
     *
     * @param list<string> $slugs
     *
     * @return array<string, string> slug => título
     */
    public function titulosPorSlug(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $mapa = [];
        foreach ($this->createQueryBuilder('a')->select('a.slug', 'a.titulo')->andWhere('a.slug IN (:slugs)')->setParameter('slugs', $slugs)->getQuery()->getArrayResult() as $linha) {
            $mapa[(string) $linha['slug']] = (string) $linha['titulo'];
        }

        return $mapa;
    }

    /**
     * Marcadores já usados nos artigos, para as sugestões do editor: agrupados pelo slug, os mais usados primeiro
     * (empate: ordem alfabética), com a grafia mais frequente de cada um. Até 10.
     *
     * @return list<array{marcador: string, usos: int}>
     */
    public function marcadoresUsados(?string $busca, int $limite = 10): array
    {
        $termo = '%' . addcslashes(mb_strtolower(trim((string) $busca)), '%_\\') . '%';
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT marcador, COUNT(*) AS usos FROM (
                SELECT jsonb_array_elements_text(marcadores) AS marcador FROM artigo_blog
             ) m
             WHERE unaccent(LOWER(marcador)) LIKE unaccent(:termo)
             GROUP BY marcador',
            ['termo' => $termo],
        );

        // Agrupa as grafias do mesmo slug: soma os usos e fica a grafia mais usada.
        $porSlug = [];
        foreach ($linhas as $linha) {
            $texto = (string) $linha['marcador'];
            $slug = Marcadores::slug($texto);
            $usos = (int) $linha['usos'];
            $porSlug[$slug]['total'] = ($porSlug[$slug]['total'] ?? 0) + $usos;
            // Empate de usos entre grafias: fica a primeira em ordem alfabética, para a sugestão não variar.
            $melhor = $porSlug[$slug]['melhor'] ?? 0;
            if ($usos > $melhor || ($usos === $melhor && strcmp($texto, $porSlug[$slug]['marcador']) < 0)) {
                $porSlug[$slug]['melhor'] = $usos;
                $porSlug[$slug]['marcador'] = $texto;
            }
        }
        $saida = array_map(static fn (array $g): array => ['marcador' => $g['marcador'], 'usos' => $g['total']], array_values($porSlug));
        $colacao = new \Collator('pt_BR');
        usort($saida, static fn (array $a, array $b): int => $b['usos'] <=> $a['usos'] ?: (int) $colacao->compare($a['marcador'], $b['marcador']));

        return \array_slice($saida, 0, $limite);
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
