<?php

namespace App\Ponto\Repository;

use App\Ponto\Entity\Feriado;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FeriadoRepository extends ServiceEntityRepository
{
    use ConsultaSemFiltroDeEscritorio;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Feriado::class);
    }

    /** @return Feriado[] */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->findBy(['tenant' => $tenant]);
    }

    /**
     * Os feriados NAQUELE escritório, com o filtro de escritório suspenso e o escritório escrito na consulta: a lista
     * de pendências lê os de outro escritório da própria pessoa (spec `ponto-esquecimento-nao-trava.md` R5).
     *
     * @return list<Feriado>
     */
    public function doEscritorio(Tenant $tenant): array
    {
        return $this->semFiltroDeEscritorio(fn (): array => $this->findBy(['tenant' => $tenant]));
    }

    /** @return Feriado[] ordenados por mês/dia */
    public function findByTenantOrdenado(Tenant $tenant): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $rows = $conn->fetchAllAssociative(
            'SELECT id FROM feriado WHERE tenant_id = :tenantId ORDER BY TO_CHAR(data, \'MM-DD\')',
            ['tenantId' => $tenant->getId()]
        );

        if (empty($rows)) {
            return [];
        }

        $ids = array_column($rows, 'id');

        // Preserva a ordem retornada pelo SQL
        $feriados = $this->findBy(['id' => $ids]);

        usort($feriados, static function (Feriado $a, Feriado $b) use ($ids): int {
            return array_search($a->getId(), $ids, true) <=> array_search($b->getId(), $ids, true);
        });

        return $feriados;
    }
}
