<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\TipoDeEnvioAoCliente;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O histórico de envios ao cliente de UMA pasta (spec push-avisar-cliente §1). Tenant explícito.
 *
 * @extends ServiceEntityRepository<EnvioAoCliente>
 */
// Não-final: os testes de UseCase mockam o repositório do domínio.
class EnvioAoClienteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnvioAoCliente::class);
    }

    public function salvar(EnvioAoCliente $envio, bool $flush = false): void
    {
        $this->getEntityManager()->persist($envio);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Fecha a transação da operação (as linhas do caderno de e-mails vão no mesmo flush). */
    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Os envios mais recentes da pasta (o "Histórico de envios" mostra 6).
     *
     * @return list<EnvioAoCliente>
     */
    public function recentesDaPasta(Tenant $tenant, Pasta $pasta, int $limite): array
    {
        /** @var list<EnvioAoCliente> $envios */
        $envios = $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->orderBy('e.em', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return $envios;
    }

    public function contarDaPasta(Tenant $tenant, Pasta $pasta): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Os envios de Análise IA da pasta, do mais antigo ao mais recente (o cartão da análise mostra
     * o último em "Enviado dd/mm às HH:MM" e todos na dica).
     *
     * @return list<EnvioAoCliente>
     */
    public function enviosDeAnalisesDaPasta(Tenant $tenant, Pasta $pasta): array
    {
        /** @var list<EnvioAoCliente> $envios */
        $envios = $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.pasta = :pasta')
            ->andWhere('e.tipo = :tipo')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->setParameter('tipo', TipoDeEnvioAoCliente::Ia)
            ->orderBy('e.em', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $envios;
    }
}
