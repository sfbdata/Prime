<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Tenant\Tenant;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\DespachoDemanda;
use App\TrabalhoExterno\Enum\Situacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Único caminho até as demandas. A entidade não é `TenantAware` (é lida pela Central, entre
 * escritórios), então **toda consulta aqui é escopada explicitamente**: pela Central (sem
 * rascunhos, que são do escritório) ou por um escritório. Não há `find` solto: quem tem um código
 * na mão passa por {@see daCentralPorId()} ou {@see doEscritorioPorId()}, conforme o perfil.
 *
 * @extends ServiceEntityRepository<DemandaExterna>
 */
class DemandaExternaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemandaExterna::class);
    }

    public function salvar(DemandaExterna $demanda, bool $flush = false): void
    {
        $this->getEntityManager()->persist($demanda);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(DemandaExterna $demanda, bool $flush = false): void
    {
        $this->getEntityManager()->remove($demanda);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * O que a Central (Master e Equipe) enxerga: as demandas enviadas de TODOS os escritórios.
     * Rascunho não sai do escritório que o escreve.
     *
     * @return list<DemandaExterna>
     */
    public function daCentral(): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.situacao <> :rascunho')
            ->setParameter('rascunho', Situacao::Rascunho)
            ->orderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<DemandaExterna> */
    public function doEscritorio(Tenant $tenant): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function daCentralPorId(int $id): ?DemandaExterna
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.id = :id')
            ->andWhere('d.situacao <> :rascunho')
            ->setParameter('id', $id)
            ->setParameter('rascunho', Situacao::Rascunho)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function doEscritorioPorId(int $id, Tenant $tenant): ?DemandaExterna
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.id = :id')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * O código da assinatura do despacho que ainda não está em uso (D8): a partir do candidato (o instante em ms),
     * o primeiro cujos 8 últimos dígitos, os que a assinatura mostra, nenhum despacho tem. Vale para todos os
     * escritórios: o código identifica a assinatura, não a demanda.
     */
    public function codigoDeDespachoLivre(int $candidato): int
    {
        $emUso = $this->getEntityManager()->createQuery(
            'SELECT COUNT(x.id) FROM ' . DespachoDemanda::class . ' x WHERE MOD(x.codigo, :ciclo) = :curto',
        )->setParameter('ciclo', DespachoDemanda::CICLO_DO_CODIGO);
        $codigo = $candidato;
        // Colidir é raro (dois despachos no mesmo milissegundo do ciclo): quase sempre uma consulta só.
        while ((int) $emUso->setParameter('curto', $codigo % DespachoDemanda::CICLO_DO_CODIGO)->getSingleScalarResult() > 0) {
            ++$codigo;
        }

        return $codigo;
    }
}
