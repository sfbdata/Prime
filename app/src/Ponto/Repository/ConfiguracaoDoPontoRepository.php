<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A configuração do Ponto por escritório (E7). Toda consulta recebe o escritório: nenhuma lê a de outro.
 *
 * @extends ServiceEntityRepository<ConfiguracaoDoPonto>
 */
// Não-final: o teste do UseCase mocka o repositório do domínio (skill criar-repository).
class ConfiguracaoDoPontoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDoPonto::class);
    }

    /** A configuração do escritório, ou null se ele nunca salvou (= os padrões, tudo ligado). */
    public function doTenant(Tenant $tenant): ?ConfiguracaoDoPonto
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }

    /**
     * A mesma linha, travada até o fim da transação (SELECT ... FOR UPDATE): dois administradores salvando ao mesmo
     * tempo não apagam a mudança um do outro no mapa de chaves. Só dentro de {@see emTransacao()}. Relê do banco
     * mesmo que a linha já esteja carregada nesta requisição.
     */
    public function doTenantTravado(Tenant $tenant): ?ConfiguracaoDoPonto
    {
        $resultado = $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $resultado instanceof ConfiguracaoDoPonto ? $resultado : null;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacao(\Closure $trabalho): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(static fn (): mixed => $trabalho());
    }

    public function salvar(ConfiguracaoDoPonto $configuracao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($configuracao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
