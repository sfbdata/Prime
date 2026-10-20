<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaObservacaoDetalhes>
 */
final class PastaObservacaoDetalhesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaObservacaoDetalhes::class);
    }

    /**
     * O conteúdo de TODAS as observações da pasta, sem teto e sem hidratar entidade: o card
     * "Pontos do relatório inicial" lê o relatório inteiro, e o relatório inicial é justamente
     * o mais antigo, o primeiro a sair do teto de exibição do `findByPasta`.
     *
     * @return list<string>
     */
    public function conteudosDaPasta(Pasta $pasta, Tenant $tenant): array
    {
        return array_map('strval', $this->createQueryBuilder('o')
            ->select('o.conteudo')
            ->andWhere('o.pasta = :pasta')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /** @return PastaObservacaoDetalhes[] */
    public function findByPasta(Pasta $pasta, Tenant $tenant, int $limit = 100): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.pasta = :pasta')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            // MAIS RECENTE PRIMEIRO: a observação recém-escrita é a que interessa ler, e é o que
            // as timelines da Pasta e da Meta já faziam. Uso exclusivo de exibição.
            ->orderBy('o.criadaEm', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * As N observações mais novas da pasta, com o autor no MESMO SELECT (a Timeline inteligente
     * mostra o nome de cada uma; sem o join, um SELECT de usuário por observação). Presa à pasta e
     * ao escritório; o teto é o mesmo das outras fontes da timeline, para o top-N da fusão ser exato.
     *
     * @return PastaObservacaoDetalhes[]
     */
    public function findParaTimeline(Pasta $pasta, Tenant $tenant, int $limite): array
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.autor', 'autor')
            ->addSelect('autor')
            ->andWhere('o.pasta = :pasta')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('o.criadaEm', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
