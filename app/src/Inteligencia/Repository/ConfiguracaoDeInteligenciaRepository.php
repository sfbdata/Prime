<?php

declare(strict_types=1);

namespace App\Inteligencia\Repository;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfiguracaoDeInteligencia>
 */
// Não-final: espelha PublicacaoDjenRepository e permite substituição por mock nos testes de UseCase.
class ConfiguracaoDeInteligenciaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfiguracaoDeInteligencia::class);
    }

    public function salvar(ConfiguracaoDeInteligencia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** A configuração do escritório, ou null se ele nunca abriu o painel (= desligada). */
    public function findDoTenant(Tenant $tenant): ?ConfiguracaoDeInteligencia
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }

    /**
     * Escritórios com a IA ligada — para o comando de status, que roda sem TenantFilter e lista
     * TODOS os tenants de propósito (é o runbook do operador da plataforma).
     *
     * @return ConfiguracaoDeInteligencia[]
     */
    public function listarHabilitadas(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.habilitada = true')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
