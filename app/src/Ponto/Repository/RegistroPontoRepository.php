<?php

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Ponto\Entity\RegistroPonto;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegistroPonto>
 */
class RegistroPontoRepository extends ServiceEntityRepository
{
    use ConsultaSemFiltroDeEscritorio;

    /** A consulta de `pontoAbertoHojeEmOutrosEscritorios`: o escopo inteiro escrito à mão (SQL nativo, sem filtro). */
    private const SQL_PONTO_ABERTO_EM_OUTROS_ESCRITORIOS = <<<'SQL'
SELECT ultima.tenant_id, t.name AS nome
FROM (
    SELECT DISTINCT ON (rp.tenant_id) rp.tenant_id, rp.tipo, rp.data_hora
    FROM registro_ponto rp
    WHERE rp.user_id = :usuario AND rp.tenant_id <> :atual AND rp.tenant_id IN (:escritorios)
      AND rp.data_hora BETWEEN :inicio AND :agora
    ORDER BY rp.tenant_id, rp.data_hora DESC, rp.id DESC
) ultima
JOIN (
    SELECT rp.tenant_id
    FROM registro_ponto rp
    WHERE rp.user_id = :usuario AND rp.tenant_id <> :atual AND rp.tenant_id IN (:escritorios)
      AND rp.data_hora BETWEEN :inicio AND :agora
    GROUP BY rp.tenant_id
    HAVING bool_or(rp.tipo = 'entrada')
) com_entrada ON com_entrada.tenant_id = ultima.tenant_id
JOIN user_tenant ut ON ut.user_id = :usuario AND ut.tenant_id = ultima.tenant_id AND ut.is_active
JOIN tenant t ON t.id = ultima.tenant_id AND t.is_active
WHERE ultima.tipo <> 'saida'
ORDER BY t.name, t.id
SQL;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistroPonto::class);
    }

    /**
     * @return RegistroPonto[]
     */
    public function findByUserAndCompetencia(User $user, int $ano, int $mes): array
    {
        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);

        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.dataHora BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('inicio', $inicioMes)
            ->setParameter('fim', $fimMes)
            ->orderBy('r.dataHora', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Data da primeira batida do colaborador no escritório — marca o início da contagem da
     * folha. Retorna null quando o colaborador ainda não registrou ponto nenhuma vez.
     */
    public function findDataPrimeiraBatida(User $user, Tenant $tenant): ?\DateTimeImmutable
    {
        // Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO).
        $primeira = $this->createQueryBuilder('r')
            ->select('MIN(r.dataHora)')
            ->andWhere('r.user = :user')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        if ($primeira === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $primeira);
    }

    /**
     * @return array<int, array{valor: string, label: string, ano: int, mes: int}>
     */
    public function findCompetenciasComRegistroPorUsuario(User $user, Tenant $tenant): array
    {
        // SQL nativo NÃO passa pelo TenantFilter: o escopo de tenant é manual e obrigatório.
        $sql = <<<'SQL'
SELECT DISTINCT
    TO_CHAR(data_hora, 'YYYY-MM') AS valor,
    TO_CHAR(data_hora, 'MM/YYYY') AS label,
    EXTRACT(YEAR FROM data_hora)::int AS ano,
    EXTRACT(MONTH FROM data_hora)::int AS mes
FROM registro_ponto
WHERE user_id = :userId AND tenant_id = :tenantId
ORDER BY valor DESC
SQL;

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, ['userId' => $user->getId(), 'tenantId' => $tenant->getId()])
            ->fetchAllAssociative();

        return array_map(static function (array $row): array {
            return [
                'valor' => (string) $row['valor'],
                'label' => (string) $row['label'],
                'ano' => (int) $row['ano'],
                'mes' => (int) $row['mes'],
            ];
        }, $rows);
    }

    /**
     * @return RegistroPonto[]
     */
    public function findBatidasDoDia(User $user, \DateTimeImmutable $dia): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.dataHora BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('inicio', $dia->setTime(0, 0, 0))
            ->setParameter('fim', $dia->setTime(23, 59, 59))
            ->orderBy('r.dataHora', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * As batidas do dia da pessoa NESTE escritório (spec `presenca-e-ponto-no-cabecalho.md` §3, atalho do cabeçalho):
     * tenant explícito além do `TenantFilter`, porque o atalho mostra o estado do ponto e não pode misturar o de outro
     * escritório da mesma pessoa. Quem escolhe a batida de cada tipo é a `EscolhaDasBatidasDoDia`, não esta consulta.
     *
     * @return list<RegistroPonto>
     */
    public function batidasDoDiaNoEscritorio(User $user, Tenant $tenant, \DateTimeImmutable $dia): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.dataHora BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('inicio', $dia->setTime(0, 0, 0))
            ->setParameter('fim', $dia->setTime(23, 59, 59))
            ->orderBy('r.dataHora', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A última batida da pessoa NESTE escritório entre `$inicio` e `$ate` (por hora e depois id), com o escritório escrito
     * na consulta: decide se a saída ou a ida ao repouso fecham um turno aberto aqui (spec seguranca-b-jornada §3.3).
     */
    public function ultimaBatidaNoTenant(User $user, Tenant $tenant, \DateTimeImmutable $inicio, \DateTimeImmutable $ate): ?RegistroPonto
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.dataHora BETWEEN :inicio AND :ate')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('inicio', $inicio)
            ->setParameter('ate', $ate)
            ->orderBy('r.dataHora', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * O primeiro repouso do dia NESTE escritório (repouso mínimo, regra da batida): tenant explícito além do filtro
     * (spec `ponto-por-escritorio.md` §2.1).
     */
    public function findRepousoDoDia(User $user, Tenant $tenant, \DateTimeImmutable $dia): ?RegistroPonto
    {
        $inicio = $dia->setTime(0, 0, 0);
        $fim    = $dia->setTime(23, 59, 59);

        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->andWhere('r.tipo = :tipo')
            ->andWhere('r.dataHora BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('tipo', RegistroPonto::TIPO_REPOUSO)
            ->setParameter('inicio', $inicio)
            ->setParameter('fim', $fim)
            ->orderBy('r.dataHora', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * A última saída NESTE escritório (interjornada, regra da batida): tenant explícito além do filtro, para a saída de
     * outro escritório nunca contar (spec `ponto-por-escritorio.md` §2.1).
     */
    public function findUltimaSaida(User $user, Tenant $tenant): ?RegistroPonto
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->andWhere('r.tipo = :tipo')
            ->setParameter('user', $user)
            ->setParameter('tipo', RegistroPonto::TIPO_SAIDA)
            ->orderBy('r.dataHora', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As batidas da pessoa NAQUELE escritório no período, em ordem de horário (lista de pendências, spec
     * `ponto-esquecimento-nao-trava.md` R5). Cross-tenant de propósito: a lista mostra à própria pessoa os outros
     * escritórios dela, então o filtro de escritório fica suspenso e o escritório vai escrito na consulta. Quem chama
     * já conferiu o vínculo ativo e o módulo de ponto.
     *
     * @return list<RegistroPonto>
     */
    public function batidasDoPeriodoNoEscritorio(User $user, Tenant $tenant, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        return $this->semFiltroDeEscritorio(fn (): array => $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.dataHora BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('inicio', $inicio)
            ->setParameter('fim', $fim)
            ->orderBy('r.dataHora', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Os OUTROS escritórios em que a pessoa está com o ponto aberto hoje (spec `ponto-por-escritorio.md` §2.2): a
     * última batida do dia até o `agora` (por horário e depois id) não é saída, e houve entrada no dia até o `agora`.
     * Batida lançada para depois do `agora` não conta.
     *
     * Cross-tenant DE PROPÓSITO: SQL nativo não passa pelo `TenantFilter`, e o escopo é todo escrito aqui. Só a própria
     * pessoa, só os escritórios em `$escritorios` (já conferidos pelo serviço: vínculo ativo e módulo de ponto), nunca o
     * atual, e de novo o vínculo ativo e o escritório ativo no JOIN. Não monta entidade: devolve o id e o nome, em
     * ordem alfabética do nome.
     *
     * @param list<int> $escritorios
     *
     * @return list<array{tenantId: int, nome: string}>
     */
    public function pontoAbertoHojeEmOutrosEscritorios(
        User $user,
        Tenant $atual,
        array $escritorios,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $agora,
    ): array {
        $linhas = $this->getEntityManager()->getConnection()->executeQuery(
            self::SQL_PONTO_ABERTO_EM_OUTROS_ESCRITORIOS,
            [
                'usuario' => $user->getId(),
                'atual' => $atual->getId(),
                'escritorios' => $escritorios,
                'inicio' => $inicio->format('Y-m-d H:i:s'),
                'agora' => $agora->format('Y-m-d H:i:s'),
            ],
            ['escritorios' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        return array_map(static fn (array $l): array => [
            'tenantId' => (int) $l['tenant_id'],
            'nome' => (string) $l['nome'],
        ], $linhas);
    }

    public function desvincularSede(Sede $sede): int
    {
        $nomeSede = $sede->getNome();

        // UPDATE em massa (DQL) NÃO aplica o TenantFilter: escopo de tenant manual via a
        // própria sede (defesa em profundidade — a sede já delimita o tenant).
        return $this->createQueryBuilder('r')
            ->update()
            ->set('r.sede', 'NULL')
            ->set('r.sedeNomeSnapshot', 'COALESCE(r.sedeNomeSnapshot, :nomeSede)')
            ->where('r.sede = :sede')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('nomeSede', $nomeSede)
            ->setParameter('sede', $sede)
            ->setParameter('tenant', $sede->getTenant())
            ->getQuery()
            ->execute();
    }
}
