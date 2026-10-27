<?php

declare(strict_types=1);

namespace App\Djen\Repository;

use App\Djen\Entity\ProcessoAcompanhado;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda consulta leva o escritório explícito: a sincronização do Push roda também no comando de captação,
 * onde o TenantFilter está desligado.
 *
 * @extends ServiceEntityRepository<ProcessoAcompanhado>
 */
class ProcessoAcompanhadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessoAcompanhado::class);
    }

    public function salvar(ProcessoAcompanhado $entidade): void
    {
        $this->getEntityManager()->persist($entidade);
        $this->getEntityManager()->flush();
    }

    public function gravar(): void
    {
        $this->getEntityManager()->flush();
    }

    public function remover(ProcessoAcompanhado $entidade): void
    {
        $this->getEntityManager()->remove($entidade);
        $this->getEntityManager()->flush();
    }

    /** @param string $numero só dígitos */
    public function findOneDoTenantPorNumero(Tenant $tenant, string $numero): ?ProcessoAcompanhado
    {
        return $this->findOneBy(['tenant' => $tenant, 'numeroProcesso' => $numero]);
    }

    /**
     * Números incluídos na mão e ainda acompanhados (a sincronização os consulta junto com os dos processos).
     *
     * @return list<string> só dígitos
     */
    public function listarNumerosAcompanhadosDoTenant(Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('a')
            ->select('a.numeroProcesso')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.acompanhando = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map('strval', $linhas));
    }

    /**
     * @param list<string> $numeros só dígitos
     *
     * @return array<string, ProcessoAcompanhado> número => anotações
     */
    public function mapaPorNumeros(Tenant $tenant, array $numeros): array
    {
        if ($numeros === []) {
            return [];
        }

        $mapa = [];
        $linhas = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.numeroProcesso IN (:numeros)')
            ->setParameter('tenant', $tenant)
            ->setParameter('numeros', $numeros)
            ->getQuery()
            ->getResult();
        foreach ($linhas as $linha) {
            $mapa[$linha->getNumeroProcesso()] = $linha;
        }

        return $mapa;
    }
}
