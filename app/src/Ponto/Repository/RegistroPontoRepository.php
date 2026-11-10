<?php

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Entity\Tenant\Tenant;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Model\AjusteDaMarcacao;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Model\BatidaAjustada;
use App\Ponto\Model\MarcacaoDoPonto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As batidas do Ponto e a linhagem dos ajustes delas.
 *
 * E10 (plano-e10-ponto §1): os leitores devolvem a batida EFETIVA, a que a folha conta ({@see MarcacaoDoPonto}). É o
 * registro original nunca ajustado ({@see RegistroPonto}) ou a cabeça de uma linhagem de `ponto_ajuste` que ainda tem
 * data e hora ({@see BatidaAjustada}: a alteração, a restauração e a inclusão do gestor). A desconsiderada some. Todos
 * saem de `SQL_EFETIVAS`; só `doEnvio` (a idempotência do envio, E13) e o `findUltimaSaida` (sem uso) leem o registro
 * original. Os leitores são SQL nativo, que não passa pelo filtro de escritório: o escritório vai escrito em cada
 * consulta, nos dois lados da união. Nenhum outro lugar lê `RegistroPonto` nem `PontoAjuste` direto
 * (`LeitorUnicoDaBatidaArquiteturaTest`).
 *
 * @extends ServiceEntityRepository<RegistroPonto>
 */
class RegistroPontoRepository extends ServiceEntityRepository
{
    /**
     * As batidas EFETIVAS do recorte (E10): o registro sem nenhum ajuste e a cabeça (o ajuste sem sucessor) de cada
     * linhagem que ainda tem data e hora. A janela `:inicio` a `:fim` vale para os dois lados: o registro pela hora
     * gravada, o ajuste pela hora nova (índice `idx_ponto_ajuste_pessoa_data`). Sem janela, `-infinity` e `infinity`.
     *
     * - `{colunas}`: as colunas do registro original (alias `o`), tiradas do mapeamento; nulas na inclusão.
     * - `{escopo_o}` e `{escopo_a}`: o mesmo recorte de pessoa e escritório (`ESCOPO_*`), no registro e no ajuste.
     * - `efetiva_*`: a forma que a folha conta. `ajuste_*`: a cabeça, nula na batida sem ajuste; `ajuste_inclusao` é a
     *   raiz da inclusão (o alvo 'a{id}'), nula na linhagem de um registro.
     *
     * Contra o ajuste forjado por SQL cru (a gravação não cria nenhum destes), um ajuste só mexe na conta da pessoa e do
     * escritório dele: preso ao registro de outro escritório ou de outra pessoa, não esconde o registro nem entra na
     * conta; o sucessor de outro escritório ou de outra pessoa não tira a cabeça da conta. A raiz da linhagem de uma
     * inclusão (`inclusao_id`) não é conferida: o ajuste forjado nela só aparece na conta da própria pessoa e do próprio
     * escritório, como uma inclusão forjada direto.
     */
    private const SQL_EFETIVAS = <<<'SQL'
SELECT {colunas}, o.data_hora AS efetiva_quando, o.tipo AS efetiva_tipo, o.user_id AS efetiva_pessoa,
       o.tenant_id AS efetiva_escritorio, NULL::int AS ajuste_id, NULL::int AS ajuste_inclusao,
       NULL::varchar AS ajuste_tipo, NULL::varchar AS ajuste_autor, NULL::timestamp AS ajuste_em,
       NULL::int AS ajuste_justificativa
FROM registro_ponto o
WHERE {escopo_o} AND o.data_hora BETWEEN :inicio AND :fim
  AND NOT EXISTS (SELECT 1 FROM ponto_ajuste l
                  WHERE l.registro_ponto_id = o.id AND l.tenant_id = o.tenant_id AND l.user_id = o.user_id)
UNION ALL
SELECT {colunas}, a.data_hora_nova, a.tipo_marcacao_nova, a.user_id, a.tenant_id, a.id,
       CASE WHEN a.registro_ponto_id IS NULL THEN COALESCE(a.inclusao_id, a.id) END,
       a.tipo, a.autor_nome, a.criado_em, a.justificativa_id
FROM ponto_ajuste a
LEFT JOIN registro_ponto o ON o.id = a.registro_ponto_id AND o.tenant_id = a.tenant_id AND o.user_id = a.user_id
WHERE {escopo_a} AND a.data_hora_nova BETWEEN :inicio AND :fim
  AND (a.registro_ponto_id IS NULL OR o.id IS NOT NULL)
  AND NOT EXISTS (SELECT 1 FROM ponto_ajuste s
                  WHERE s.anterior_id = a.id AND s.tenant_id = a.tenant_id AND s.user_id = a.user_id)
SQL;

    /** O recorte das efetivas: a pessoa NESTE escritório. `{x}` vira o alias do registro e o do ajuste. */
    private const ESCOPO_DA_PESSOA = '{x}.user_id = :usuario AND {x}.tenant_id = :escritorio';

    /** O recorte das efetivas: a equipe inteira NESTE escritório. */
    private const ESCOPO_DA_EQUIPE = '{x}.tenant_id = :escritorio';

    /** O recorte das efetivas: a pessoa nos OUTROS escritórios com que ela tem vínculo ativo. */
    private const ESCOPO_DOS_OUTROS_ESCRITORIOS = '{x}.user_id = :usuario AND {x}.tenant_id <> :escritorio'
        . ' AND EXISTS (SELECT 1 FROM user_tenant ut WHERE ut.user_id = {x}.user_id AND ut.tenant_id = {x}.tenant_id AND ut.is_active)';

    /** O recorte das efetivas: a pessoa nos escritórios da lista, nunca no atual. */
    private const ESCOPO_DOS_ESCRITORIOS_DA_LISTA = '{x}.user_id = :usuario AND {x}.tenant_id <> :escritorio AND {x}.tenant_id IN (:escritorios)';

    /** As colunas do registro quando a consulta não monta a batida (só agrega ou devolve horários). */
    private const SO_O_ID = 'o.id AS id';

    /**
     * A ordem das efetivas (plano-e10-ponto §1): hora, o registro (a inclusão, sem registro, por último) e o ajuste. A
     * inversa é a mesma lida de trás para frente (no PostgreSQL, ASC põe o nulo por último e DESC, primeiro).
     */
    private const EM_ORDEM = 'e.efetiva_quando, e.id, e.ajuste_id';
    private const EM_ORDEM_INVERSA = 'e.efetiva_quando DESC, e.id DESC, e.ajuste_id DESC';

    /** As colunas da forma efetiva e da cabeça que a montagem da batida lê, com o tipo de cada uma. */
    private const COLUNAS_DA_EFETIVA = [
        'efetiva_quando' => 'datetime_immutable',
        'efetiva_tipo' => 'string',
        'efetiva_pessoa' => 'integer',
        'ajuste_id' => 'integer',
        'ajuste_inclusao' => 'integer',
        'ajuste_tipo' => 'string',
        'ajuste_autor' => 'string',
        'ajuste_em' => 'datetime_immutable',
        'ajuste_justificativa' => 'integer',
    ];

    /** Sem janela de datas: todas as batidas da pessoa (o PostgreSQL compara timestamp com o infinito). */
    private const DESDE_SEMPRE = '-infinity';
    private const ATE_SEMPRE = 'infinity';

    /**
     * A consulta de `pontoAbertoHojeEmOutrosEscritorios`: o escopo inteiro escrito à mão (SQL nativo, sem filtro), sobre
     * as batidas efetivas (`{efetivas}`).
     */
    private const SQL_PONTO_ABERTO_EM_OUTROS_ESCRITORIOS = <<<'SQL'
WITH e AS ({efetivas})
SELECT ultima.tenant_id, t.name AS nome
FROM (
    SELECT DISTINCT ON (e.efetiva_escritorio) e.efetiva_escritorio AS tenant_id, e.efetiva_tipo AS tipo
    FROM e
    ORDER BY e.efetiva_escritorio, e.efetiva_quando DESC, e.id DESC, e.ajuste_id DESC
) ultima
JOIN (
    SELECT e.efetiva_escritorio AS tenant_id
    FROM e
    GROUP BY e.efetiva_escritorio
    HAVING bool_or(e.efetiva_tipo = 'entrada')
) com_entrada ON com_entrada.tenant_id = ultima.tenant_id
JOIN user_tenant ut ON ut.user_id = :usuario AND ut.tenant_id = ultima.tenant_id AND ut.is_active
JOIN tenant t ON t.id = ultima.tenant_id AND t.is_active
WHERE ultima.tipo <> 'saida'
ORDER BY t.name, t.id
SQL;

    /**
     * A consulta de `ultimaSaidaQueFechouTurno`: a batida efetiva imediatamente anterior a cada saída (LAG, na ordem
     * das efetivas) da pessoa NESTE escritório; vale a saída mais recente até `:fim` cuja anterior é entrada ou
     * retorno, dentro da janela do turno.
     */
    private const SQL_ULTIMA_SAIDA_QUE_FECHOU_TURNO = <<<'SQL'
SELECT b.*
FROM (
    SELECT e.*,
           LAG(e.efetiva_tipo) OVER (ORDER BY e.efetiva_quando, e.id, e.ajuste_id) AS tipo_anterior,
           LAG(e.efetiva_quando) OVER (ORDER BY e.efetiva_quando, e.id, e.ajuste_id) AS quando_anterior
    FROM ({efetivas}) e
) b
WHERE b.efetiva_tipo = 'saida'
  AND b.tipo_anterior IN ('entrada', 'retorno')
  AND b.quando_anterior >= b.efetiva_quando - make_interval(hours => :janela)
ORDER BY b.efetiva_quando DESC, b.id DESC, b.ajuste_id DESC
LIMIT 1
SQL;

    /** E10: o maior id de uma coluna INT do PostgreSQL; alvo de ajuste acima disso não existe (e não vira erro de banco). */
    private const MAIOR_ID = 2147483647;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistroPonto::class);
    }

    /**
     * As batidas efetivas da competência NESTE escritório: alimentam a folha, o espelho exportado e o saldo do banco de
     * horas.
     *
     * @return list<MarcacaoDoPonto>
     */
    public function findByUserAndCompetencia(User $user, Tenant $tenant, int $ano, int $mes): array
    {
        $inicioMes = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $fimMes = $inicioMes->modify('last day of this month')->setTime(23, 59, 59);

        // Escritório escrito na consulta: é dado de ponto (risco ALTO, auditoria 1.13).
        return $this->efetivas(self::ESCOPO_DA_PESSOA, 'ORDER BY ' . self::EM_ORDEM, self::daPessoa($user, $tenant, $inicioMes, $fimMes));
    }

    /**
     * Data da primeira batida efetiva do colaborador no escritório: marca o início da contagem da folha. A inclusão do
     * gestor conta; a desconsiderada, não. Retorna null quando o colaborador ainda não tem batida nenhuma.
     */
    public function findDataPrimeiraBatida(User $user, Tenant $tenant): ?\DateTimeImmutable
    {
        $primeira = $this->conexao()->fetchOne(
            sprintf('SELECT MIN(e.efetiva_quando) FROM (%s) e', self::sqlDasEfetivas(self::ESCOPO_DA_PESSOA, self::SO_O_ID)),
            self::daPessoa($user, $tenant, self::DESDE_SEMPRE, self::ATE_SEMPRE),
        );
        if ($primeira === null || $primeira === false) {
            return null;
        }

        return new \DateTimeImmutable((string) $primeira);
    }

    /**
     * Os meses com batida efetiva da pessoa NESTE escritório, do mais novo para o mais antigo (o seletor de competência).
     *
     * @return array<int, array{valor: string, label: string, ano: int, mes: int}>
     */
    public function findCompetenciasComRegistroPorUsuario(User $user, Tenant $tenant): array
    {
        // SQL nativo NÃO passa pelo TenantFilter: o escopo de tenant é manual e obrigatório.
        $sql = sprintf(<<<'SQL'
SELECT DISTINCT
    TO_CHAR(e.efetiva_quando, 'YYYY-MM') AS valor,
    TO_CHAR(e.efetiva_quando, 'MM/YYYY') AS label,
    EXTRACT(YEAR FROM e.efetiva_quando)::int AS ano,
    EXTRACT(MONTH FROM e.efetiva_quando)::int AS mes
FROM (%s) e
ORDER BY valor DESC
SQL, self::sqlDasEfetivas(self::ESCOPO_DA_PESSOA, self::SO_O_ID));

        $rows = $this->conexao()
            ->executeQuery($sql, self::daPessoa($user, $tenant, self::DESDE_SEMPRE, self::ATE_SEMPRE))
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
     * As batidas efetivas do dia NESTE escritório (aviso de horário do ponto).
     *
     * @return list<MarcacaoDoPonto>
     */
    public function findBatidasDoDia(User $user, Tenant $tenant, \DateTimeImmutable $dia): array
    {
        // Escritório escrito na consulta: é dado de ponto (risco ALTO, auditoria 1.13).
        return $this->efetivas(self::ESCOPO_DA_PESSOA, 'ORDER BY ' . self::EM_ORDEM, self::daPessoa($user, $tenant, $dia->setTime(0, 0, 0), $dia->setTime(23, 59, 59)));
    }

    /**
     * As batidas efetivas do dia da pessoa NESTE escritório (spec `presenca-e-ponto-no-cabecalho.md` §3, atalho do
     * cabeçalho): o escritório vai escrito na consulta, porque o atalho mostra o estado do ponto e não pode misturar o
     * de outro escritório da mesma pessoa. Quem escolhe a batida de cada tipo é a `EscolhaDasBatidasDoDia`, não esta
     * consulta.
     *
     * @return list<MarcacaoDoPonto>
     */
    public function batidasDoDiaNoEscritorio(User $user, Tenant $tenant, \DateTimeImmutable $dia): array
    {
        return $this->efetivas(self::ESCOPO_DA_PESSOA, 'ORDER BY ' . self::EM_ORDEM, self::daPessoa($user, $tenant, $dia->setTime(0, 0, 0), $dia->setTime(23, 59, 59)));
    }

    /**
     * As batidas efetivas do dia de TODA a equipe NESTE escritório, numa consulta só (aba "Equipe" da /ponto, spec
     * `ponto-desenho-0811.md` E5): a situação de hoje de cada pessoa. O escritório vai escrito na consulta; quem
     * escolhe a batida de cada tipo é a `EscolhaDasBatidasDoDia`, não esta consulta.
     *
     * @return list<MarcacaoDoPonto> por pessoa e, dentro dela, na ordem das efetivas
     */
    public function batidasDoDiaDaEquipe(Tenant $tenant, \DateTimeImmutable $dia): array
    {
        return $this->efetivas(self::ESCOPO_DA_EQUIPE, 'ORDER BY e.efetiva_pessoa, ' . self::EM_ORDEM, [
            'escritorio' => $tenant->getId(),
            'inicio' => self::quando($dia->setTime(0, 0, 0)),
            'fim' => self::quando($dia->setTime(23, 59, 59)),
        ]);
    }

    /**
     * A última batida efetiva da pessoa NESTE escritório entre `$inicio` e `$ate` (na ordem das efetivas), com o
     * escritório escrito na consulta: decide se a saída ou a ida ao repouso fecham um turno aberto aqui (spec
     * seguranca-b-jornada §3.3).
     */
    public function ultimaBatidaNoTenant(User $user, Tenant $tenant, \DateTimeImmutable $inicio, \DateTimeImmutable $ate): ?MarcacaoDoPonto
    {
        return $this->efetivas(self::ESCOPO_DA_PESSOA, 'ORDER BY ' . self::EM_ORDEM_INVERSA . ' LIMIT 1', self::daPessoa($user, $tenant, $inicio, $ate))[0] ?? null;
    }

    /**
     * A batida da pessoa NESTE escritório que já entrou com este identificador de envio (E13, spec ponto-desenho-0811):
     * a nova tentativa do mesmo toque responde "já registrada" em vez de gravar de novo. Tenant explícito além do filtro;
     * o mesmo envio gravado em outro escritório não aparece aqui, e quem o barra é o índice único (user_id, id_envio).
     *
     * E10: lê o registro ORIGINAL, de propósito. O envio já gravado é o mesmo toque, mesmo que um ajuste o tenha
     * corrigido ou tirado da conta depois: gravar de novo seria a duplicata que a idempotência existe para impedir.
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
     * O primeiro repouso efetivo do dia NESTE escritório (repouso mínimo, regra da batida): escritório escrito na
     * consulta (spec `ponto-por-escritorio.md` §2.1). Com `$ate`, só o repouso até esse instante do dia: a batida
     * guardada sem internet (spec ponto-desenho-0811 E14) não mede o intervalo contra um repouso batido depois dela.
     */
    public function findRepousoDoDia(User $user, Tenant $tenant, \DateTimeImmutable $dia, ?\DateTimeImmutable $ate = null): ?MarcacaoDoPonto
    {
        $inicio = $dia->setTime(0, 0, 0);
        $fim    = $ate !== null ? min($ate, $dia->setTime(23, 59, 59)) : $dia->setTime(23, 59, 59);

        return $this->efetivas(
            self::ESCOPO_DA_PESSOA,
            'WHERE e.efetiva_tipo = :tipo ORDER BY ' . self::EM_ORDEM . ' LIMIT 1',
            self::daPessoa($user, $tenant, $inicio, $fim) + ['tipo' => RegistroPonto::TIPO_REPOUSO],
        )[0] ?? null;
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
     * A última saída efetiva da pessoa NESTE escritório que FECHOU um turno: a batida efetiva imediatamente anterior a
     * ela (na ordem das efetivas, no mesmo escritório) é entrada ou retorno, no máximo `$janelaHoras` antes
     * (interjornada, spec `ponto-esquecimento-nao-trava.md` R4). A saída solta não conta. Sem limite de quantas saídas
     * olhar. SQL nativo com usuário e escritório explícitos: não depende do filtro.
     *
     * Só a saída até `$ate` (sem ele, até agora): a batida guardada sem internet (spec ponto-desenho-0811 E14) mede a
     * interjornada contra a saída anterior a ela, nunca contra uma saída batida depois.
     */
    public function ultimaSaidaQueFechouTurno(User $user, Tenant $tenant, int $janelaHoras, ?\DateTimeImmutable $ate = null): ?MarcacaoDoPonto
    {
        return $this->hidratadas(
            self::SQL_ULTIMA_SAIDA_QUE_FECHOU_TURNO,
            self::ESCOPO_DA_PESSOA,
            self::daPessoa($user, $tenant, self::DESDE_SEMPRE, $ate ?? new \DateTimeImmutable()) + ['janela' => $janelaHoras],
        )[0] ?? null;
    }

    /**
     * As batidas efetivas da pessoa NAQUELE escritório no período, na ordem das efetivas. Duas fontes usam: a lista de
     * pendências (spec `ponto-esquecimento-nao-trava.md` R5) e o banco de horas por lotes (spec ponto-desenho-0811,
     * E17), que lê todos os meses desde o início da contagem numa consulta só
     * (`FolhaPontoBuilder::saldosDoBancoComJornada`). Cross-tenant de propósito: a lista mostra à própria pessoa os
     * outros escritórios dela; o SQL nativo não passa pelo filtro de escritório, e o escritório vai escrito na consulta
     * (o banco passa sempre o escritório da folha). Quem chama já conferiu o vínculo ativo e o módulo de ponto.
     *
     * @return list<MarcacaoDoPonto>
     */
    public function batidasDoPeriodoNoEscritorio(User $user, Tenant $tenant, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        return $this->efetivas(self::ESCOPO_DA_PESSOA, 'ORDER BY ' . self::EM_ORDEM, self::daPessoa($user, $tenant, $inicio, $fim));
    }

    /**
     * Só os HORÁRIOS das batidas efetivas da pessoa em qualquer outro escritório no período
     * (`RegistroEmOutroEscritorioNoTurno`, spec `ponto-esquecimento-nao-trava.md` R8): o sinal da folha do admin diz só
     * que houve registro, nunca onde. Só os escritórios com que a pessoa tem vínculo ativo. Cross-tenant de propósito
     * (SQL nativo, fora do filtro de escritório); a consulta não devolve escritório nem entidade.
     *
     * @return list<\DateTimeImmutable>
     */
    public function horariosEmOutrosEscritorios(User $user, Tenant $exceto, \DateTimeImmutable $inicio, \DateTimeImmutable $fim): array
    {
        $horarios = $this->conexao()->fetchFirstColumn(
            sprintf('SELECT e.efetiva_quando FROM (%s) e ORDER BY e.efetiva_quando', self::sqlDasEfetivas(self::ESCOPO_DOS_OUTROS_ESCRITORIOS, self::SO_O_ID)),
            self::daPessoa($user, $exceto, $inicio, $fim),
        );

        return array_map(static fn (mixed $quando): \DateTimeImmutable => new \DateTimeImmutable((string) $quando), $horarios);
    }

    /**
     * Os OUTROS escritórios em que a pessoa está com o ponto aberto hoje (spec `ponto-por-escritorio.md` §2.2): a
     * última batida efetiva do dia até o `agora` (na ordem das efetivas) não é saída, e houve entrada no dia até o
     * `agora`. Batida lançada para depois do `agora` não conta.
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
        $linhas = $this->conexao()->executeQuery(
            str_replace('{efetivas}', self::sqlDasEfetivas(self::ESCOPO_DOS_ESCRITORIOS_DA_LISTA, self::SO_O_ID), self::SQL_PONTO_ABERTO_EM_OUTROS_ESCRITORIOS),
            self::daPessoa($user, $atual, $inicio, $agora) + ['escritorios' => $escritorios],
            ['escritorios' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        return array_map(static fn (array $l): array => [
            'tenantId' => (int) $l['tenant_id'],
            'nome' => (string) $l['nome'],
        ], $linhas);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // E10 (plano-e10-ponto §1): a montagem das batidas efetivas.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * As efetivas do recorte, montadas, com o que vem depois da união (`$depois`: filtro, ordem e limite sobre `e`).
     *
     * @param array<string, mixed> $parametros
     *
     * @return list<MarcacaoDoPonto>
     */
    private function efetivas(string $escopo, string $depois, array $parametros): array
    {
        return $this->hidratadas('SELECT e.* FROM ({efetivas}) e ' . $depois, $escopo, $parametros);
    }

    /**
     * Roda a consulta (com `{efetivas}` no lugar da união) e monta cada linha: o registro original, gerido pelo ORM,
     * quando não há ajuste; a {@see BatidaAjustada} da cabeça, quando há.
     *
     * @param array<string, mixed> $parametros
     *
     * @return list<MarcacaoDoPonto>
     */
    private function hidratadas(string $consulta, string $escopo, array $parametros): array
    {
        $mapa = new ResultSetMappingBuilder($this->getEntityManager());
        $mapa->addRootEntityFromClassMetadata(RegistroPonto::class, 'o');
        foreach (self::COLUNAS_DA_EFETIVA as $coluna => $tipo) {
            $mapa->addScalarResult($coluna, $coluna, $tipo);
        }
        $sql = str_replace('{efetivas}', self::sqlDasEfetivas($escopo, $mapa->generateSelectClause()), $consulta);

        /** @var list<array<int|string, mixed>> $linhas */
        $linhas = $this->getEntityManager()->createNativeQuery($sql, $mapa)->setParameters($parametros)->getResult();

        return array_map(self::efetivaDaLinha(...), $linhas);
    }

    /** @param array<int|string, mixed> $linha */
    private static function efetivaDaLinha(array $linha): MarcacaoDoPonto
    {
        $original = $linha[0] ?? null;
        if ($linha['ajuste_id'] === null) {
            return $original instanceof RegistroPonto ? $original : throw new \LogicException('Batida efetiva sem registro e sem ajuste.');
        }
        $original = $original instanceof RegistroPonto ? $original : null;

        return new BatidaAjustada(
            $original?->getId(),
            $linha['ajuste_inclusao'] === null ? null : (int) $linha['ajuste_inclusao'],
            (int) $linha['ajuste_id'],
            (int) $linha['efetiva_pessoa'],
            (string) $linha['efetiva_tipo'],
            self::instante($linha['efetiva_quando']),
            new AjusteDaMarcacao(
                TipoDoAjuste::from((string) $linha['ajuste_tipo']),
                (string) $linha['ajuste_autor'],
                self::instante($linha['ajuste_em']),
                $linha['ajuste_justificativa'] === null ? null : (int) $linha['ajuste_justificativa'],
            ),
            $original?->isHomeOffice() ?? false,
            $original?->getObservacao(),
            $original?->isTrabalhoExterno() ?? false,
        );
    }

    private static function instante(mixed $valor): \DateTimeImmutable
    {
        return $valor instanceof \DateTimeImmutable ? $valor : throw new \LogicException('Batida efetiva sem data e hora.');
    }

    /** A união das efetivas para o recorte, com as colunas do registro pedidas. */
    private static function sqlDasEfetivas(string $escopo, string $colunas): string
    {
        return strtr(self::SQL_EFETIVAS, [
            '{colunas}' => $colunas,
            '{escopo_o}' => str_replace('{x}', 'o', $escopo),
            '{escopo_a}' => str_replace('{x}', 'a', $escopo),
        ]);
    }

    /**
     * Os parâmetros do recorte da pessoa num escritório (no dos outros escritórios, o escritório é o que fica de fora).
     *
     * @return array{usuario: ?int, escritorio: ?int, inicio: string, fim: string}
     */
    private static function daPessoa(User $user, Tenant $tenant, \DateTimeInterface|string $inicio, \DateTimeInterface|string $fim): array
    {
        return ['usuario' => $user->getId(), 'escritorio' => $tenant->getId(), 'inicio' => self::quando($inicio), 'fim' => self::quando($fim)];
    }

    private static function quando(\DateTimeInterface|string $instante): string
    {
        return $instante instanceof \DateTimeInterface ? $instante->format('Y-m-d H:i:s') : $instante;
    }

    private function conexao(): Connection
    {
        return $this->getEntityManager()->getConnection();
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
     * Os meses em que a pessoa tem batida fora da conta por desconsideração NESTE escritório, pela data da batida
     * desconsiderada (o mesmo recorte de `desconsideradasDaCompetencia`). A ficha monta a folha desses meses mesmo sem
     * batida efetiva: o mês que perdeu a última batida para uma desconsideração continua com a linha "Desconsideradas" e
     * o Restaurar.
     *
     * @return list<string> 'AAAA-MM', do mais novo para o mais antigo
     */
    public function competenciasComDesconsideracao(User $pessoa, Tenant $tenant): array
    {
        // A coluna volta como texto do banco ('AAAA-MM-DD HH:MM:SS'): a leitura de uma coluna só não converte o tipo.
        $batidas = $this->cabecasDeLinhagem()
            ->select('a.dataHoraAnterior')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.user = :pessoa')
            ->andWhere('a.tipo = :tipo')
            ->setParameter('tenant', $tenant)
            ->setParameter('pessoa', $pessoa)
            ->setParameter('tipo', TipoDoAjuste::Desconsideracao->value)
            ->getQuery()
            ->getSingleColumnResult();

        $competencias = [];
        foreach ($batidas as $batida) {
            $quando = $batida instanceof \DateTimeInterface ? $batida : new \DateTimeImmutable((string) $batida);
            $competencias[$quando->format('Y-m')] = true;
        }
        krsort($competencias, SORT_STRING);

        return array_map('strval', array_keys($competencias));
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

    /** A pessoa já bateu ponto neste escritório: alguma batida gravada, desconsiderada ou não. */
    public function existeDoUsuarioNoEscritorio(User $user, Tenant $tenant): bool
    {
        return $this->createQueryBuilder('r')
            ->select('r.id')
            ->andWhere('r.user = :user')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult() !== null;
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
            ->andWhere('a.user = j.user')
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

    /**
     * A última linha da linhagem do registro original, ou nula se ele nunca foi ajustado. Só a linha do mesmo escritório
     * e da mesma pessoa do registro (a de outra, só por SQL cru, não conta, como em `SQL_EFETIVAS`).
     */
    private function cabecaDaBatida(RegistroPonto $batida, Tenant $tenant): ?PontoAjuste
    {
        $cabeca = $this->cabecasDeLinhagem()
            ->andWhere('a.registroPonto = :raiz')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.user = :pessoa')
            ->setParameter('raiz', $batida)
            ->setParameter('tenant', $tenant)
            ->setParameter('pessoa', $batida->getUser())
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
            ->andWhere('a.user = :pessoa')
            ->setParameter('raiz', $inclusao->getId())
            ->setParameter('tenant', $tenant)
            ->setParameter('pessoa', $pessoa)
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

    /**
     * Os ajustes que ninguém sucedeu (nenhuma linha do mesmo escritório e da mesma pessoa tem `anterior` apontando para
     * eles): as cabeças de linhagem. O sucessor de outro escritório ou de outra pessoa (só por SQL cru) não tira a
     * cabeça, como em `SQL_EFETIVAS`: a gravação e a folha veem a mesma cabeça.
     */
    private function cabecasDeLinhagem(): QueryBuilder
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('a')
            ->from(PontoAjuste::class, 'a')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT s.id FROM %s s WHERE s.anterior = a AND s.tenant = a.tenant AND s.user = a.user)',
                PontoAjuste::class,
            ));
    }
}
