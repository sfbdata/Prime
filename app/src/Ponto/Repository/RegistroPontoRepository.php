<?php

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Model\AlvoDoAjuste;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * A consulta de `ultimaSaidaQueFechouTurno`: a batida imediatamente anterior a cada saída (LAG, por hora e id) da
     * pessoa NESTE escritório; vale a saída mais recente até `:ate` cuja anterior é entrada ou retorno, dentro da janela
     * do turno.
     */
    private const SQL_ULTIMA_SAIDA_QUE_FECHOU_TURNO = <<<'SQL'
SELECT b.id
FROM (
    SELECT rp.id, rp.tipo, rp.data_hora,
           LAG(rp.tipo) OVER (ORDER BY rp.data_hora, rp.id) AS tipo_anterior,
           LAG(rp.data_hora) OVER (ORDER BY rp.data_hora, rp.id) AS quando_anterior
    FROM registro_ponto rp
    WHERE rp.user_id = :usuario AND rp.tenant_id = :escritorio AND rp.data_hora <= :ate
) b
WHERE b.tipo = 'saida'
  AND b.tipo_anterior IN ('entrada', 'retorno')
  AND b.quando_anterior >= b.data_hora - make_interval(hours => :janela)
ORDER BY b.data_hora DESC, b.id DESC
LIMIT 1
SQL;

    /** E10: o maior id de uma coluna INT do PostgreSQL; alvo de ajuste acima disso não existe (e não vira erro de banco). */
    private const MAIOR_ID = 2147483647;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistroPonto::class);
    }

    /**
     * As batidas da competência NESTE escritório: alimentam a folha, o espelho exportado e o saldo do banco de horas.
     *
     * @return RegistroPonto[]
     */
    public function findByUserAndCompetencia(User $user, Tenant $tenant, int $ano, int $mes): array
    {
        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);

        // Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO, auditoria 1.13).
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.dataHora BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
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
     * As batidas do dia NESTE escritório (aviso de horário do ponto).
     *
     * @return RegistroPonto[]
     */
    public function findBatidasDoDia(User $user, Tenant $tenant, \DateTimeImmutable $dia): array
    {
        // Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO, auditoria 1.13).
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
     * As batidas do dia de TODA a equipe NESTE escritório, numa consulta só (aba "Equipe" da /ponto, spec
     * `ponto-desenho-0811.md` E5): a situação de hoje de cada pessoa. O escritório vai escrito na consulta, além do
     * filtro; quem escolhe a batida de cada tipo é a `EscolhaDasBatidasDoDia`, não esta consulta.
     *
     * @return list<RegistroPonto> por pessoa e, dentro dela, por horário
     */
    public function batidasDoDiaDaEquipe(Tenant $tenant, \DateTimeImmutable $dia): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.dataHora BETWEEN :inicio AND :fim')
            ->setParameter('tenant', $tenant)
            ->setParameter('inicio', $dia->setTime(0, 0, 0))
            ->setParameter('fim', $dia->setTime(23, 59, 59))
            ->orderBy('r.user', 'ASC')
            ->addOrderBy('r.dataHora', 'ASC')
            ->addOrderBy('r.id', 'ASC')
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
     * A batida da pessoa NESTE escritório que já entrou com este identificador de envio (E13, spec ponto-desenho-0811):
     * a nova tentativa do mesmo toque responde "já registrada" em vez de gravar de novo. Tenant explícito além do filtro;
     * o mesmo envio gravado em outro escritório não aparece aqui, e quem o barra é o índice único (user_id, id_envio).
     */
    public function doEnvio(User $user, Tenant $tenant, string $idEnvio): ?RegistroPonto
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.idEnvio = :idEnvio')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('idEnvio', $idEnvio)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As batidas da pessoa NESTE escritório que vieram da fila sem internet com um destes identificadores de envio (E14,
     * evento "Sincronização"). Tenant explícito além do filtro; o identificador de outra pessoa ou de outro escritório não
     * acha nada.
     *
     * @param list<string> $idsEnvio
     *
     * @return list<RegistroPonto>
     */
    public function semInternetDosEnvios(User $user, Tenant $tenant, array $idsEnvio): array
    {
        if ($idsEnvio === []) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.idEnvio IN (:ids)')
            ->andWhere('r.enviadaSemInternet = true')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $idsEnvio)
            ->orderBy('r.dataHora', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * O primeiro repouso do dia NESTE escritório (repouso mínimo, regra da batida): tenant explícito além do filtro
     * (spec `ponto-por-escritorio.md` §2.1). Com `$ate`, só o repouso até esse instante do dia: a batida guardada sem
     * internet (spec ponto-desenho-0811 E14) não mede o intervalo contra um repouso batido depois dela.
     */
    public function findRepousoDoDia(User $user, Tenant $tenant, \DateTimeImmutable $dia, ?\DateTimeImmutable $ate = null): ?RegistroPonto
    {
        $inicio = $dia->setTime(0, 0, 0);
        $fim    = $ate !== null ? min($ate, $dia->setTime(23, 59, 59)) : $dia->setTime(23, 59, 59);

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
     * A última saída da pessoa NESTE escritório que FECHOU um turno: a batida imediatamente anterior a ela (por hora e
     * id, no mesmo escritório) é entrada ou retorno, no máximo `$janelaHoras` antes (interjornada, spec
     * `ponto-esquecimento-nao-trava.md` R4). A saída solta não conta. Sem limite de quantas saídas olhar. SQL nativo com
     * usuário e escritório explícitos: não depende do filtro.
     *
     * Só a saída até `$ate` (sem ele, até agora): a batida guardada sem internet (spec ponto-desenho-0811 E14) mede a
     * interjornada contra a saída anterior a ela, nunca contra uma saída batida depois.
     */
    public function ultimaSaidaQueFechouTurno(User $user, Tenant $tenant, int $janelaHoras, ?\DateTimeImmutable $ate = null): ?RegistroPonto
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(self::SQL_ULTIMA_SAIDA_QUE_FECHOU_TURNO, [
            'usuario' => $user->getId(),
            'escritorio' => $tenant->getId(),
            'janela' => $janelaHoras,
            'ate' => ($ate ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        if ($id === false || $id === null) {
            return null;
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.id = :id')
            ->andWhere('r.user = :user')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('id', (int) $id)
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As batidas da pessoa NAQUELE escritório no período, em ordem de horário. Duas fontes usam: a lista de pendências
     * (spec `ponto-esquecimento-nao-trava.md` R5) e o banco de horas por lotes (spec ponto-desenho-0811, E17), que lê
     * todos os meses desde o início da contagem numa consulta só (`FolhaPontoBuilder::saldosDoBancoComJornada`).
     * Cross-tenant de propósito: a lista mostra à própria pessoa os outros escritórios dela, então o filtro de
     * escritório fica suspenso e o escritório vai escrito na consulta (o banco passa sempre o escritório da folha).
     * Quem chama já conferiu o vínculo ativo e o módulo de ponto.
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
     * Só os HORÁRIOS das batidas da pessoa em qualquer outro escritório no período (`RegistroEmOutroEscritorioNoTurno`,
     * spec `ponto-esquecimento-nao-trava.md` R8): o sinal da folha do admin diz só que houve registro, nunca onde. Só os
     * escritórios com que a pessoa tem vínculo ativo. O filtro de escritório fica suspenso de propósito; a consulta não
     * devolve escritório nem entidade.
     *
     * @return list<\DateTimeImmutable>
     */
    public function horariosEmOutrosEscritorios(User $user, Tenant $exceto, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        $linhas = $this->semFiltroDeEscritorio(fn (): array => $this->createQueryBuilder('r')
            ->select('r.dataHora AS quando')
            ->andWhere('r.user = :user')
            ->andWhere('r.tenant <> :exceto')
            ->andWhere('EXISTS (SELECT ut.id FROM App\Entity\Auth\UserTenant ut WHERE ut.user = r.user AND ut.tenant = r.tenant AND ut.isActive = true)')
            ->andWhere('r.dataHora BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('exceto', $exceto)
            ->setParameter('inicio', $inicio)
            ->setParameter('fim', $fim)
            ->orderBy('r.dataHora', 'ASC')
            ->getQuery()
            ->getArrayResult());

        return array_map(static fn (array $l): \DateTimeImmutable => \DateTimeImmutable::createFromInterface($l['quando']), $linhas);
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

    // ---------------------------------------------------------------------------------------------------------------
    // E10 (plano-e10-ponto §6): a linhagem dos ajustes de batida (`ponto_ajuste`). Toda consulta leva o escritório
    // escrito à mão, além do filtro; alvo de outro escritório ou de outra pessoa não existe para quem pede.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * A batida que um ajuste vai corrigir: 'b{id}' é o registro original da pessoa NESTE escritório; 'a{id}' é uma
     * inclusão da pessoa NESTE escritório. Devolve a raiz, a cabeça da linhagem (o ajuste sem sucessor) e a forma que a
     * folha conta agora (tipo e data/hora nulos quando a cabeça é uma desconsideração). Alvo malformado, de outra pessoa
     * ou de outro escritório: nulo. Quem vai gravar lê sob a trava da pessoa ({@see \App\Ponto\Service\GravacaoDoAjusteDoPonto::travar}).
     */
    public function alvoDoAjuste(User $pessoa, Tenant $tenant, string $alvo): ?AlvoDoAjuste
    {
        if (preg_match('/^([ba])([1-9]\d{0,9})$/', $alvo, $partes) !== 1 || (int) $partes[2] > self::MAIOR_ID) {
            return null;
        }

        return $partes[1] === 'b'
            ? $this->alvoNaBatida($pessoa, $tenant, (int) $partes[2])
            : $this->alvoNaInclusao($pessoa, $tenant, (int) $partes[2]);
    }

    /**
     * As desconsiderações da pessoa NESTE escritório que ainda são a cabeça da linhagem (a batida está fora da conta),
     * com a batida desconsiderada na competência: a linha "Desconsideradas" da ficha, com o Restaurar. Inclui as feitas
     * pela análise da justificativa; quem decide se restaura é o UseCase.
     *
     * @return list<PontoAjuste> em ordem da batida desconsiderada
     */
    public function desconsideradasDaCompetencia(User $pessoa, Tenant $tenant, int $ano, int $mes): array
    {
        $inicio = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));

        return $this->cabecasDeLinhagem()
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.user = :pessoa')
            ->andWhere('a.tipo = :tipo')
            ->andWhere('a.dataHoraAnterior >= :inicio')
            ->andWhere('a.dataHoraAnterior < :fim')
            ->setParameter('tenant', $tenant)
            ->setParameter('pessoa', $pessoa)
            ->setParameter('tipo', TipoDoAjuste::Desconsideracao->value)
            ->setParameter('inicio', $inicio)
            ->setParameter('fim', $inicio->modify('first day of next month'))
            ->orderBy('a.dataHoraAnterior', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A inclusão que o abono deste esquecimento criou, como alvo de ajuste (para a volta à análise desconsiderá-la).
     * Do escritório e da pessoa da justificativa; se a justificativa foi abonada mais de uma vez, a inclusão mais nova.
     */
    public function inclusaoDoEsquecimento(JustificativaPonto $justificativa): ?AlvoDoAjuste
    {
        $pessoa = $justificativa->getUser();
        $tenant = $justificativa->getTenant();
        if ($pessoa === null || $tenant === null || $justificativa->getId() === null) {
            return null;
        }

        $inclusao = $this->getEntityManager()->createQueryBuilder()
            ->select('i')
            ->from(PontoAjuste::class, 'i')
            ->andWhere('i.justificativa = :justificativa')
            ->andWhere('i.tipo = :tipo')
            ->andWhere('i.tenant = :tenant')
            ->andWhere('i.user = :pessoa')
            ->setParameter('justificativa', $justificativa)
            ->setParameter('tipo', TipoDoAjuste::Inclusao->value)
            ->setParameter('tenant', $tenant)
            ->setParameter('pessoa', $pessoa)
            ->orderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $inclusao instanceof PontoAjuste ? $this->alvoDaInclusao($pessoa, $tenant, $inclusao) : null;
    }

    /**
     * A justificativa já mexeu na folha: algum ajuste (inclusão do esquecimento, desconsideração da análise) aponta para
     * ela. O escritório do ajuste tem de ser o da justificativa.
     */
    public function existeAjusteDaJustificativa(int $justificativaId): bool
    {
        $achado = $this->getEntityManager()->createQueryBuilder()
            ->select('a.id')
            ->from(PontoAjuste::class, 'a')
            ->join('a.justificativa', 'j')
            ->andWhere('j.id = :justificativa')
            ->andWhere('a.tenant = j.tenant')
            ->setParameter('justificativa', $justificativaId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $achado !== null;
    }

    /**
     * A desconsideração ligada a esta justificativa que AINDA é a cabeça da linhagem (ninguém a restaurou nem ajustou
     * depois), ou nula: a E12 a restaura quando o gestor volta para análise a recusa de um trabalho externo. O
     * escritório do ajuste tem de ser o da justificativa.
     */
    public function desconsideracaoDaJustificativa(int $justificativaId): ?PontoAjuste
    {
        $desconsideracao = $this->cabecasDeLinhagem()
            ->join('a.justificativa', 'j')
            ->andWhere('j.id = :justificativa')
            ->andWhere('a.tenant = j.tenant')
            ->andWhere('a.tipo = :tipo')
            ->setParameter('justificativa', $justificativaId)
            ->setParameter('tipo', TipoDoAjuste::Desconsideracao->value)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $desconsideracao instanceof PontoAjuste ? $desconsideracao : null;
    }

    private function alvoNaBatida(User $pessoa, Tenant $tenant, int $registroId): ?AlvoDoAjuste
    {
        $batida = $this->createQueryBuilder('r')
            ->andWhere('r.id = :id')
            ->andWhere('r.user = :pessoa')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('id', $registroId)
            ->setParameter('pessoa', $pessoa)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$batida instanceof RegistroPonto) {
            return null;
        }
        // Sem ajuste (cabeça nula), a forma atual é a do próprio registro.
        $cabeca   = $this->cabecaDaBatida($batida, $tenant);
        $original = $batida->getDataHora();
        $tipo     = $cabeca !== null ? $cabeca->getTipoMarcacaoNova() : $batida->getTipo();
        $dataHora = $cabeca !== null ? $cabeca->getDataHoraNova() : ($original === null ? null : \DateTimeImmutable::createFromInterface($original));

        return new AlvoDoAjuste($tenant, $pessoa, 'b' . $registroId, $batida, null, $cabeca, $tipo, $dataHora);
    }

    /** A última linha da linhagem do registro original, ou nula se ele nunca foi ajustado. */
    private function cabecaDaBatida(RegistroPonto $batida, Tenant $tenant): ?PontoAjuste
    {
        $cabeca = $this->cabecasDeLinhagem()
            ->andWhere('a.registroPonto = :raiz')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('raiz', $batida)
            ->setParameter('tenant', $tenant)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $cabeca instanceof PontoAjuste ? $cabeca : null;
    }

    private function alvoNaInclusao(User $pessoa, Tenant $tenant, int $inclusaoId): ?AlvoDoAjuste
    {
        $inclusao = $this->getEntityManager()->createQueryBuilder()
            ->select('i')
            ->from(PontoAjuste::class, 'i')
            ->andWhere('i.id = :id')
            ->andWhere('i.tipo = :tipo')
            ->andWhere('i.user = :pessoa')
            ->andWhere('i.tenant = :tenant')
            ->setParameter('id', $inclusaoId)
            ->setParameter('tipo', TipoDoAjuste::Inclusao->value)
            ->setParameter('pessoa', $pessoa)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();

        return $inclusao instanceof PontoAjuste ? $this->alvoDaInclusao($pessoa, $tenant, $inclusao) : null;
    }

    /** A inclusão como alvo: a cabeça é a última linha da linhagem dela, ou a própria inclusão se ninguém a ajustou. */
    private function alvoDaInclusao(User $pessoa, Tenant $tenant, PontoAjuste $inclusao): AlvoDoAjuste
    {
        $cabeca = $this->cabecasDeLinhagem()
            ->andWhere('a.inclusao = :raiz OR a.id = :raiz')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('raiz', $inclusao->getId())
            ->setParameter('tenant', $tenant)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        $cabeca = $cabeca instanceof PontoAjuste ? $cabeca : $inclusao;

        return new AlvoDoAjuste(
            $tenant,
            $pessoa,
            'a' . $inclusao->getId(),
            null,
            $inclusao,
            $cabeca,
            $cabeca->getTipoMarcacaoNova(),
            $cabeca->getDataHoraNova(),
        );
    }

    /** Os ajustes que ninguém sucedeu (nenhuma linha tem `anterior` apontando para eles): as cabeças de linhagem. */
    private function cabecasDeLinhagem(): QueryBuilder
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('a')
            ->from(PontoAjuste::class, 'a')
            ->andWhere(sprintf('NOT EXISTS (SELECT s.id FROM %s s WHERE s.anterior = a)', PontoAjuste::class));
    }
}
