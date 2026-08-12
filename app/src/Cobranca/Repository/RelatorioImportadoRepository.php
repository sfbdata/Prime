<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Enum\TipoRelatorioContabil;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelatorioImportado>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class RelatorioImportadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelatorioImportado::class);
    }

    public function salvar(RelatorioImportado $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * O lote já lido deste arquivo, nesta versão do leitor — a checagem de idempotência (SPEC §4.2).
     *
     * O filtro de tenant é aplicado pelo `TenantFilter`, que age sobre `TenantAware`; a chave única
     * do banco cobre o mesmo par no nível do schema.
     */
    public function findOnePorHash(
        Carteira $carteira,
        string $arquivoHash,
        int $versaoLeitor,
    ): ?RelatorioImportado {
        return $this->createQueryBuilder('r')
            ->andWhere('r.carteira = :carteira')
            ->andWhere('r.arquivoHash = :hash')
            ->andWhere('r.versaoLeitor = :versao')
            ->setParameter('carteira', $carteira)
            ->setParameter('hash', $arquivoHash)
            ->setParameter('versao', $versaoLeitor)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * O lote mais recente de uma carteira, pela data em que a contabilidade fechou os números
     * (`dadosAte`), não pela data em que lemos. É `dadosAte` que ancora a calibração.
     */
    public function findUltimoDaCarteira(
        Carteira $carteira,
        TipoRelatorioContabil $tipo = TipoRelatorioContabil::Inadimplencia,
    ): ?RelatorioImportado {
        return $this->createQueryBuilder('r')
            ->andWhere('r.carteira = :carteira')
            ->andWhere('r.tipo = :tipo')
            ->setParameter('carteira', $carteira)
            ->setParameter('tipo', $tipo)
            ->orderBy('r.dadosAte', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
