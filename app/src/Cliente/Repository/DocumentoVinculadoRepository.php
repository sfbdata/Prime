<?php

declare(strict_types=1);

namespace App\Cliente\Repository;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Consultas dos documentos do cadastro VINCULADOS ao menu Documentos (Inteligência cadastral, F-A). Fica no domínio
 * Cliente para não crescer o repositório legado `App\Repository\ClienteDocumentoRepository`. Tenant explícito.
 */
class DocumentoVinculadoRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<int> os documentos da pasta já vinculados ao cadastro deste cliente */
    public function idsDePastaDocumentoVinculados(Cliente $cliente, Tenant $tenant): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('IDENTITY(d.pastaDocumento) AS id')
            ->from(ClienteDocumento::class, 'd')
            ->andWhere('d.cliente = :cliente')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.pastaDocumento IS NOT NULL')
            ->setParameter('cliente', $cliente)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map('intval', $ids));
    }
}
