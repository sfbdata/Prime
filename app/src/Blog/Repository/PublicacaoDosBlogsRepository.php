<?php

declare(strict_types=1);

namespace App\Blog\Repository;

use App\Blog\Entity\PublicacaoDosBlogs;
use App\Blog\Enum\SituacaoDaPublicacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Gerações dos blogs. Sem filtro de tenant de propósito: a publicação é da plataforma, pedida só pelo Master (ver
 * {@see PublicacaoDosBlogs}); nenhum escritório lê ou grava aqui.
 *
 * @extends ServiceEntityRepository<PublicacaoDosBlogs>
 */
class PublicacaoDosBlogsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicacaoDosBlogs::class);
    }

    public function salvar(PublicacaoDosBlogs $publicacao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($publicacao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function porId(int $id): ?PublicacaoDosBlogs
    {
        return $this->find($id);
    }

    /** O pedido na fila cujo pacote ainda não foi montado: um Publicar novo o reaproveita (seção 6.1). */
    public function naFilaSemPacote(): ?PublicacaoDosBlogs
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.situacao = :naFila')
            ->andWhere('p.artigos IS NULL')
            ->setParameter('naFila', SituacaoDaPublicacao::NaFila)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Os pedidos na fila ou gerando, os mais antigos primeiro (a conferência do resultado do builder).
     *
     * @return list<PublicacaoDosBlogs>
     */
    public function abertas(): array
    {
        /** @var list<PublicacaoDosBlogs> */
        return $this->createQueryBuilder('p')
            ->andWhere('p.situacao IN (:abertas)')
            ->setParameter('abertas', [SituacaoDaPublicacao::NaFila->value, SituacaoDaPublicacao::Gerando->value])
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * As últimas gerações, as mais novas primeiro (a faixa e o histórico da lista de artigos).
     *
     * @return list<PublicacaoDosBlogs>
     */
    public function ultimas(int $limite = 20): array
    {
        /** @var list<PublicacaoDosBlogs> */
        return $this->createQueryBuilder('p')
            ->leftJoin('p.pedidaPor', 'u')
            ->addSelect('u')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
