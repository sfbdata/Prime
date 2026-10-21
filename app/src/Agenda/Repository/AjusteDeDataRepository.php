<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use App\Agenda\Entity\AjusteDeData;
use App\Agenda\Enum\TipoDeAjuste;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Ajustes de datas do escritório na Agenda. Toda consulta é pelo escritório.
 *
 * @extends ServiceEntityRepository<AjusteDeData>
 */
class AjusteDeDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AjusteDeData::class);
    }

    /** @return list<AjusteDeData> */
    public function doEscritorio(Tenant $tenant): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function doEscritorioPorId(int $id, Tenant $tenant): ?AjusteDeData
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    public function daData(string $dataId, TipoDeAjuste $tipo, Tenant $tenant): ?AjusteDeData
    {
        return $this->findOneBy(['dataId' => $dataId, 'tipo' => $tipo, 'tenant' => $tenant]);
    }

    /**
     * "Restaurar datas do sistema": apaga os renomes e as ocultações; as datas acrescentadas ficam.
     * Remove uma a uma pelo EntityManager (não por DQL) para cada remoção entrar na auditoria.
     */
    public function apagarRenomesEOcultacoes(Tenant $tenant): int
    {
        $linhas = $this->findBy(['tenant' => $tenant, 'tipo' => [TipoDeAjuste::Renomear, TipoDeAjuste::Ocultar]]);
        foreach ($linhas as $linha) {
            $this->getEntityManager()->remove($linha);
        }
        $this->getEntityManager()->flush();

        return count($linhas);
    }

    public function salvar(AjusteDeData $ajuste): void
    {
        $this->getEntityManager()->persist($ajuste);
        $this->getEntityManager()->flush();
    }

    public function remover(AjusteDeData $ajuste): void
    {
        $this->getEntityManager()->remove($ajuste);
        $this->getEntityManager()->flush();
    }
}
