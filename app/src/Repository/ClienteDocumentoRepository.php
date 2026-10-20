<?php

namespace App\Repository;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClienteDocumento>
 */
class ClienteDocumentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClienteDocumento::class);
    }

    public function salvar(ClienteDocumento $documento, bool $flush = false): void
    {
        $this->getEntityManager()->persist($documento);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Os documentos da pasta já vinculados ao cadastro deste cliente (Inteligência cadastral, F-A), tenant explícito.
     *
     * @return list<int>
     */
    public function idsDePastaDocumentoVinculados(Cliente $cliente, Tenant $tenant): array
    {
        $ids = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.pastaDocumento) AS id')
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
