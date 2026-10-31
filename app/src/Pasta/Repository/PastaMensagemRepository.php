<?php

namespace App\Pasta\Repository;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaMensagem>
 */
class PastaMensagemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaMensagem::class);
    }

    /**
     * Uma mensagem pelo id, desta pasta E deste escritório — o guard IDOR de editar/excluir, que
     * recebem o id da mensagem na URL. Filtro explícito: não depende de o TenantFilter estar ligado.
     */
    public function findByIdAndPastaAndTenant(int $id, Pasta $pasta, Tenant $tenant): ?PastaMensagem
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id = :id')
            ->andWhere('m.pasta = :pasta')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return PastaMensagem[]
     */
    public function findByPasta(Pasta $pasta, Tenant $tenant, int $limit = 150): array
    {
        // Autor e original respondida vêm na MESMA consulta: a linha do tempo lê o nome de cada
        // autor, e sem o join cada autor distinto custava um SELECT a mais (N+1). Só relações
        // para-um — o `setMaxResults` continua valendo por mensagem.
        return $this->createQueryBuilder('m')
            ->leftJoin('m.autor', 'autor')->addSelect('autor')
            ->leftJoin('m.respostaA', 'raiz')->addSelect('raiz')
            ->leftJoin('raiz.autor', 'raizAutor')->addSelect('raizAutor')
            ->andWhere('m.pasta = :pasta')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('m.criadaEm', 'DESC')
            // Cartões gravados no mesmo flush (contrato e fase do selo) empatam no criadaEm: o id desempata.
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Os registros escritos por pessoas (sem os cartões do sistema) das pastas pedidas, para o
     * relatório de pastas sem processo: o texto (ainda com HTML), o autor e quando. Uma consulta
     * para o lote; tenant pela pasta.
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, list<array{conteudo: string, autor: string, em: \DateTimeImmutable}>> pastaId => registros
     */
    public function registrosParaAnaliseSemProcesso(array $pastaIds, Tenant $tenant): array
    {
        if ($pastaIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.pasta) AS pasta_id', 'm.conteudo', 'm.criadaEm', 'a.fullName', 'a.email')
            ->join('m.pasta', 'p')
            ->leftJoin('m.autor', 'a')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.id IN (:ids)')
            ->andWhere('m.sistema IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $pastaIds)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $porPasta = [];
        foreach ($linhas as $l) {
            $porPasta[(int) $l['pasta_id']][] = [
                'conteudo' => (string) $l['conteudo'],
                'autor'    => (string) ($l['fullName'] ?? $l['email'] ?? ''),
                'em'       => $l['criadaEm'],
            ];
        }

        return $porPasta;
    }

    /**
     * Quando foi o registro mais recente escrito por pessoa (sem os cartões do sistema) de cada pasta
     * pedida, para o relatório de pastas paradas. Uma consulta para o lote; tenant pela pasta.
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, \DateTimeImmutable> pastaId => data e hora do último registro
     */
    public function ultimoRegistroPorPasta(array $pastaIds, Tenant $tenant): array
    {
        if ($pastaIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.pasta) AS pasta_id', 'MAX(m.criadaEm) AS ultimo')
            ->join('m.pasta', 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.id IN (:ids)')
            ->andWhere('m.sistema IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $pastaIds)
            ->groupBy('m.pasta')
            ->getQuery()
            ->getArrayResult();

        $porPasta = [];
        foreach ($linhas as $l) {
            $porPasta[(int) $l['pasta_id']] = new \DateTimeImmutable((string) $l['ultimo']);
        }

        return $porPasta;
    }

    /**
     * O último cartão do sistema de cada tipo, por pasta, entre as pastas pedidas deste escritório. Chave = id da pasta.
     *
     * @param list<int> $idsPasta
     *
     * @return array<int, PastaMensagem>
     */
    public function ultimoDoSistemaPorPasta(Tenant $tenant, string $tipo, array $idsPasta): array
    {
        if ($idsPasta === []) {
            return [];
        }
        $mensagens = $this->createQueryBuilder('m')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.sistema = :tipo')
            ->andWhere('IDENTITY(m.pasta) IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('tipo', $tipo)
            ->setParameter('ids', $idsPasta)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();

        $porPasta = [];
        foreach ($mensagens as $m) {
            $porPasta[(int) $m->getPasta()?->getId()] = $m;
        }

        return $porPasta;
    }
}
