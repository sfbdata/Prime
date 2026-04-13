<?php

namespace App\Repository;

use App\Cliente\Entity\Cliente;
use App\Entity\Pasta\Pasta;
use App\Expediente\Entity\Marcador;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pasta>
 */
class PastaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pasta::class);
    }

    /**
     * @param array<string, string> $filters
     * @return Pasta[]
     */
    public function findByFilters(array $filters): array
    {
        $qb = $this->createQueryBuilder('p');

        if (!empty($filters['nup'])) {
            $qb->andWhere('p.nup LIKE :nup')
               ->setParameter('nup', '%' . $filters['nup'] . '%');
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['responsavel'])) {
            $qb->join('p.responsavel', 'r')
               ->andWhere('r.id = :responsavel')
               ->setParameter('responsavel', (int) $filters['responsavel']);
        }

        if (!empty($filters['status_documentos'])) {
            if ($filters['status_documentos'] === 'APTO_PARA_PROTOCOLAR') {
                $qb->andWhere('p.docPecaOk = true AND p.docProcuracaoOk = true AND p.docIdentificacaoOk = true AND p.docComprovanteResidenciaOk = true AND p.docGratuidadeJusticaOk = true');
            } else {
                $qb->andWhere('p.docPecaOk = false OR p.docProcuracaoOk = false OR p.docIdentificacaoOk = false OR p.docComprovanteResidenciaOk = false OR p.docGratuidadeJusticaOk = false');
            }
        }

        return $qb->orderBy('p.id', 'DESC')->getQuery()->getResult();
    }

    /**
     * @return string[]
     */
    public function findAllNups(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.nup')
            ->orderBy('p.nup', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'nup');
    }

    /**
     * @return Pasta[]
     */
    public function findByCliente(Cliente $cliente): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.clientes', 'c')
            ->where('c = :cliente')
            ->setParameter('cliente', $cliente)
            ->orderBy('p.dataAbertura', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Pasta[]
     */
    public function findPorMarcador(Marcador $marcador): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.marcadores', 'm')
            ->where('m = :marcador')
            ->setParameter('marcador', $marcador)
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, string> $filters
     * @return Pasta[]
     */
    public function findByFiltrosEMarcador(array $filters, Marcador $marcador): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.marcadores', 'm')
            ->where('m = :marcador')
            ->setParameter('marcador', $marcador);

        if (!empty($filters['nup'])) {
            $qb->andWhere('p.nup LIKE :nup')
               ->setParameter('nup', '%' . $filters['nup'] . '%');
        }

        if (!empty($filters['status'])) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['responsavel'])) {
            $qb->join('p.responsavel', 'r')
               ->andWhere('r.id = :responsavel')
               ->setParameter('responsavel', (int) $filters['responsavel']);
        }

        if (!empty($filters['status_documentos'])) {
            if ($filters['status_documentos'] === 'APTO_PARA_PROTOCOLAR') {
                $qb->andWhere('p.docPecaOk = true AND p.docProcuracaoOk = true AND p.docIdentificacaoOk = true AND p.docComprovanteResidenciaOk = true AND p.docGratuidadeJusticaOk = true');
            } else {
                $qb->andWhere('p.docPecaOk = false OR p.docProcuracaoOk = false OR p.docIdentificacaoOk = false OR p.docComprovanteResidenciaOk = false OR p.docGratuidadeJusticaOk = false');
            }
        }

        return $qb->orderBy('p.id', 'DESC')->getQuery()->getResult();
    }
}
