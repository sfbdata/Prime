<?php

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaDocumento>
 */
class PastaDocumentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaDocumento::class);
    }

    /** @return PastaDocumento[] */
    /**
     * Chaves (`caminho_arquivo`) das peças de texto — os documentos HTML — de um escritório.
     *
     * Usado por `ArquivosReferenciadosEmPecas` para saber quais arquivos precisam ser lidos em
     * busca de referências embutidas. O filtro de tenant é EXPLÍCITO (não depende só do
     * TenantFilter): esta consulta alimenta decisões sobre apagar arquivo, e aqui um vazamento
     * de escopo não daria erro — daria exclusão errada.
     *
     * @return string[]
     */
    public function chavesDePecasHtmlDoTenant(Tenant $tenant): array
    {
        /** @var string[] $chaves */
        $chaves = $this->createQueryBuilder('d')
            ->select('d.caminhoArquivo')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.mimeType = :mime')
            ->setParameter('tenant', $tenant)
            ->setParameter('mime', 'text/html')
            ->getQuery()
            ->getSingleColumnResult();

        return $chaves;
    }

    /**
     * Documentos do escritório com o MESMO conteúdo (`sha256` igual) — a consulta por trás do aviso
     * "este arquivo já existe em…" do upload.
     *
     * Filtro de tenant EXPLÍCITO (não depende do TenantFilter): o resultado aponta para pastas de
     * outras telas, e um vazamento aqui mostraria a um escritório o título e o número de pasta de
     * outro. Pastas excluídas (lápide) ficam de fora — o arquivo delas não está "em uso". Linhas com
     * `sha256` NULL nunca casam (NULL = ainda não calculado). A permissão por pasta NÃO é daqui: é
     * do use case, que filtra com o `PermissionChecker`.
     *
     * @return list<PastaDocumento> com a pasta já carregada, do mais antigo ao mais novo
     */
    public function comOMesmoConteudo(Tenant $tenant, string $sha256, ?int $excetoDocumentoId = null, int $limite = 50): array
    {
        $qb = $this->createQueryBuilder('d')
            ->addSelect('p')
            ->innerJoin('d.pasta', 'p')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.sha256 = :sha256')
            ->andWhere('p.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('sha256', $sha256)
            ->orderBy('d.carregadoEm', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->setMaxResults($limite);

        if ($excetoDocumentoId !== null) {
            $qb->andWhere('d.id <> :excetoId')->setParameter('excetoId', $excetoDocumentoId);
        }

        /** @var list<PastaDocumento> $documentos */
        $documentos = $qb->getQuery()->getResult();

        return $documentos;
    }

    /**
     * Ids dos documentos ainda sem hash, do mais antigo ao mais novo — a fila de
     * `app:documentos:calcular-hash`. Só ids (escalar) para o comando percorrer em lotes sem
     * hidratar o acervo inteiro.
     *
     * @return list<int>
     */
    public function idsSemSha256(?int $tenantId = null, ?int $limite = null): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.id')
            ->andWhere('d.sha256 IS NULL')
            ->orderBy('d.id', 'ASC');

        if ($tenantId !== null) {
            $qb->andWhere('d.tenant = :tenantId')->setParameter('tenantId', $tenantId);
        }

        if ($limite !== null) {
            $qb->setMaxResults($limite);
        }

        return array_map('intval', $qb->getQuery()->getSingleColumnResult());
    }

    public function contarSemSha256(?int $tenantId = null): int
    {
        $qb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.sha256 IS NULL');

        if ($tenantId !== null) {
            $qb->andWhere('d.tenant = :tenantId')->setParameter('tenantId', $tenantId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Todos os documentos de uma pasta, com a seção JÁ carregada, na ordem manual — a consulta
     * única por trás do explorador da aba Documentos (`ExploradorDeDocumentosOutput`).
     *
     * Uma query só: a pasta de produção com 1.128 documentos não pode acordar 1.128 proxies de
     * seção na renderização. Filtro de tenant EXPLÍCITO além do TenantFilter: o JSON que sai
     * daqui vira linha na tela, e uma linha de outro escritório seria vazamento, não erro.
     *
     * @return list<PastaDocumento>
     */
    public function findByPastaComSecao(Pasta $pasta, Tenant $tenant): array
    {
        /** @var list<PastaDocumento> $documentos */
        $documentos = $this->createQueryBuilder('d')
            ->addSelect('s')
            ->leftJoin('d.secao', 's')
            ->andWhere('d.pasta = :pasta')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('d.ordem', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $documentos;
    }

    public function findByPastaECategoria(Pasta $pasta, string $categoria): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.pasta = :pasta')
            ->andWhere('d.categoria = :categoria')
            ->setParameter('pasta', $pasta)
            ->setParameter('categoria', $categoria)
            ->orderBy('d.carregadoEm', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
