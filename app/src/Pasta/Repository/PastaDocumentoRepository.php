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
