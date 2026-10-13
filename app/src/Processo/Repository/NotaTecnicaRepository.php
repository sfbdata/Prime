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
     * Todas as notas de um processo (inclusive as penduradas em publicações), em ordem
     * CRONOLÓGICA — a mais antiga primeiro, a nota nova no fim (C6-FPN-01; desenho dc:5395/5397,
     * `A.push`): o bloco se lê como conversa, de cima para baixo, e o compositor fica embaixo.
     * Uso exclusivo de exibição.
     *
     * O teto continua pegando as `$limite` MAIS RECENTES (a consulta ordena da mais nova e corta);
     * só a página devolvida é invertida. Ordenar ASC no SQL com o teto esconderia as novas.
     *
     * @return NotaTecnica[]
     */
    public function listarPorProcesso(Processo $processo, Tenant $tenant, int $limite = 100): array
    {
        // Fetch-join do autor e da publicação: o Output lê os dois, e sem isso cada nota da tela
        // disparava duas consultas preguiçosas.
        return $this->emOrdemCronologica($this->createQueryBuilder('n')
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
            ->getResult());
    }

    /**
     * Só as notas penduradas NESTA movimentação do Push Processual, em ordem cronológica (a nova
     * no fim, C6-FPN-01) — o mesmo bloco que a aba pendura sob o cartão.
     *
     * @return NotaTecnica[]
     */
    public function listarPorPublicacao(PublicacaoDjen $publicacao, Tenant $tenant, int $limite = 100): array
    {
        // Fetch-join do autor e da publicação: o Output lê os dois, e sem isso cada nota da tela
        // disparava duas consultas preguiçosas.
        return $this->emOrdemCronologica($this->createQueryBuilder('n')
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
            ->getResult());
    }

    /**
     * As notas penduradas nas movimentações de UMA página da aba Push da pasta, numa consulta só
     * (FPN-020: a nota aparece sob o cartão com o teor fechado). Em ordem cronológica, como no
     * teor (C6-FPN-01); quem agrupa por publicação é o `PastaPushOutput`, que mantém a ordem.
     *
     * Três recortes, todos na consulta: o escritório (explícito — não depende do TenantFilter de
     * sessão), as publicações da lista e os processos DA PASTA. O último importa porque a nota é do
     * processo: uma nota da mesma publicação feita por outro processo (de outra pasta) não é desta
     * pasta. Sem publicação ou sem processo, nem consulta.
     *
     * @param int[]      $publicacaoIds
     * @param Processo[] $processos     os processos vinculados à pasta
     *
     * @return NotaTecnica[]
     */
    public function listarPorPublicacoesDosProcessos(array $publicacaoIds, array $processos, Tenant $tenant, int $limite = 500): array
    {
        $publicacaoIds = array_values(array_unique(array_filter(array_map('intval', $publicacaoIds), static fn (int $id): bool => $id > 0)));
        if ($publicacaoIds === [] || $processos === []) {
            return [];
        }

        // Fetch-join do autor e da publicação: o Output lê os dois (sem isso, duas consultas
        // preguiçosas por nota da página).
        return $this->emOrdemCronologica($this->createQueryBuilder('n')
            ->addSelect('a', 'pub')
            ->leftJoin('n.autor', 'a')
            ->innerJoin('n.publicacaoDjen', 'pub')
            ->andWhere('pub.id IN (:publicacoes)')
            ->andWhere('n.processo IN (:processos)')
            ->andWhere('n.tenant = :tenant')
            ->setParameter('publicacoes', $publicacaoIds)
            ->setParameter('processos', array_values($processos))
            ->setParameter('tenant', $tenant)
            ->orderBy('n.criadaEm', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult());
    }

    /**
     * As consultas de exibição pedem da mais nova para a mais antiga (para o teto cortar as
     * antigas) e a tela lê na ordem em que foram escritas: inverte a página.
     *
     * @param NotaTecnica[] $notas
     *
     * @return NotaTecnica[]
     */
    private function emOrdemCronologica(array $notas): array
    {
        return array_reverse(array_values($notas));
    }
}
