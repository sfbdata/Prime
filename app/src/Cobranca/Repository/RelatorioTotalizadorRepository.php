<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Entity\RelatorioTotalizador;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelatorioTotalizador>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class RelatorioTotalizadorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelatorioTotalizador::class);
    }

    public function salvar(RelatorioTotalizador $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * As linhas do rodapé somado, na ordem do arquivo — insumo da reconciliação interna (INV-T1).
     *
     * @return list<RelatorioTotalizador>
     */
    public function doRelatorio(RelatorioImportado $relatorio): array
    {
        /** @var list<RelatorioTotalizador> $linhas */
        $linhas = $this->createQueryBuilder('t')
            ->andWhere('t.relatorio = :relatorio')
            ->setParameter('relatorio', $relatorio)
            ->orderBy('t.numeroLinha', 'ASC')
            ->getQuery()
            ->getResult();

        return $linhas;
    }
}
