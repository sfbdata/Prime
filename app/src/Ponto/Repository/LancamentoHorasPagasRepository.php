<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\LancamentoHorasPagas;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LancamentoHorasPagas>
 */
class LancamentoHorasPagasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LancamentoHorasPagas::class);
    }

    /**
     * Soma, com sinal, os lançamentos do colaborador naquela competência. 0 quando não há nenhum.
     *
     * Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO).
     */
    public function somarPorCompetencia(User $user, Tenant $tenant, int $ano, int $mes): int
    {
        $soma = $this->createQueryBuilder('l')
            ->select('SUM(l.minutos)')
            ->andWhere('l.user = :user')
            ->andWhere('l.tenant = :tenant')
            ->andWhere('l.ano = :ano')
            ->andWhere('l.mes = :mes')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('ano', $ano)
            ->setParameter('mes', $mes)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $soma;
    }

    /**
     * Soma, com sinal, os lançamentos de um intervalo de meses do MESMO ano — inclusive nas duas
     * pontas. Uma query só: o painel `/ponto` é caminho quente (todo funcionário abre várias vezes
     * por dia) e o agregador anual varria janeiro a dezembro com um SELECT por mês.
     *
     * `SUM` sem nenhuma linha devolve NULL no Postgres; o cast para int transforma em 0.
     *
     * Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO).
     */
    public function somarPorPeriodo(User $user, Tenant $tenant, int $ano, int $mesInicial, int $mesFinal): int
    {
        $soma = $this->createQueryBuilder('l')
            ->select('SUM(l.minutos)')
            ->andWhere('l.user = :user')
            ->andWhere('l.tenant = :tenant')
            ->andWhere('l.ano = :ano')
            ->andWhere('l.mes >= :mesInicial')
            ->andWhere('l.mes <= :mesFinal')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('ano', $ano)
            ->setParameter('mesInicial', $mesInicial)
            ->setParameter('mesFinal', $mesFinal)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $soma;
    }

    /**
     * Lançamentos do colaborador no escritório, mais recentes primeiro. Alimenta a ficha do admin.
     *
     * @return LancamentoHorasPagas[]
     */
    public function listarPorUser(User $user, Tenant $tenant): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :user')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->orderBy('l.ano', 'DESC')
            ->addOrderBy('l.mes', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Competências (formato `'YYYY-MM'`) em que o colaborador tem AO MENOS UM lançamento de horas
     * pagas — inclusive meses sem nenhuma batida, que `RegistroPontoRepository::
     * findCompetenciasComRegistroPorUsuario` nunca devolve. Alimenta o seletor da ficha do admin:
     * sem isto, um lançamento num mês sem nenhuma batida fica inalcançável pelo dropdown — o admin
     * só chegaria lá editando a URL na mão.
     *
     * Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO).
     *
     * @return string[] 'YYYY-MM', mais recente primeiro
     */
    public function listarCompetenciasComLancamento(User $user, Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('l')
            ->select('DISTINCT l.ano AS ano', 'l.mes AS mes')
            ->andWhere('l.user = :user')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->orderBy('l.ano', 'DESC')
            ->addOrderBy('l.mes', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $linha): string => sprintf('%04d-%02d', (int) $linha['ano'], (int) $linha['mes']),
            $linhas,
        );
    }

    /**
     * Busca por id EXIGINDO o tenant — nunca buscar lançamento só por id vindo da URL (IDOR).
     */
    public function buscarDoTenant(int $id, Tenant $tenant): ?LancamentoHorasPagas
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.id = :id')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
