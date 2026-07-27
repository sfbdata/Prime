<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\ResultadoContato;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventoHistorico>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class EventoHistoricoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventoHistorico::class);
    }

    public function salvar(EventoHistorico $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(EventoHistorico $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca tenant-safe por id — o filtro SQL do Doctrine NÃO se aplica a find() por PK
     * (risco cross-tenant). Sempre passar o tenant explícito.
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?EventoHistorico
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Linha do tempo do caso, do mais antigo ao mais recente (SPEC §13). Escopo por tenant do caso.
     *
     * @return EventoHistorico[]
     */
    public function doCaso(CasoCobranca $caso): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.caso = :caso')
            ->andWhere('e.tenant = :tenant')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            // MAIS RECENTE PRIMEIRO: o histórico é lido de cima para baixo, e o que acabou de
            // acontecer é o que interessa. Alinha com as timelines da Pasta e da Meta, que já
            // ordenavam assim. Uso exclusivo de exibição (`MontarDetalheCasoUseCase`) — nenhum
            // cálculo depende desta ordem.
            ->orderBy('e.ocorridoEm', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A qualificação de contato MAIS RECENTE do caso, ou `null` se ainda não houver nenhuma.
     *
     * É a quarta condição de desfazer da spec §3.5 — a única que um evento sozinho não sabe responder,
     * porque depende dos irmãos. Consulta dedicada, com `setMaxResults(1)`, em vez de reaproveitar
     * `doCaso()`: aquela carrega a linha do tempo INTEIRA (contatos, pagamentos, acordos) só para olhar
     * a primeira linha de um tipo.
     *
     * Desempate por `id DESC` além do `ocorridoEm DESC`: dois cliques dentro do mesmo segundo têm a
     * mesma data, e sem o desempate qual delas é "a última" ficaria a critério do plano de execução do
     * Postgres. Mesma ordenação de `doCaso()`, para a listinha do painel e esta guarda nunca
     * discordarem sobre quem está no topo.
     */
    public function ultimaQualificacaoDoCaso(CasoCobranca $caso): ?EventoHistorico
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.caso = :caso')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.tipo = :tipo')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->setParameter('tipo', TipoEventoHistorico::QualificacaoContato)
            ->orderBy('e.ocorridoEm', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Atividade da equipe no período, agregada por pessoa (Central de Acompanhamento, Fatia 1 §5/§9).
     *
     * SQL NATIVO de propósito: as contagens condicionais (`COUNT(*) FILTER`) e a leitura do desfecho no
     * payload (`dados->>'resultado'`) não têm equivalente em DQL, e agregar em PHP significaria carregar
     * todos os eventos do período — exatamente o que a spec proíbe. Uma consulta, um GROUP BY.
     *
     * O `LEFT JOIN` no usuário traz o nome junto: quem trabalhou e depois perdeu o acesso ao módulo (ou
     * saiu da equipe) continua identificável, em vez de virar um id solto. Evento órfão
     * (`usuario_id IS NULL`) forma seu próprio grupo, com nome nulo.
     *
     * Faixa fechada no início e ABERTA no fim (`>= inicio AND < fimExclusivo`): evita o clássico buraco
     * do último segundo do dia. SQL nativo não passa pelos filtros do Doctrine — por isso `tenant_id` é
     * filtrado explicitamente aqui.
     *
     * "Última ação" é `MAX` **restrito aos tipos de trabalho de cobrança** (spec §5.1): sem o FILTER,
     * quem só subiu planilha apareceria com ação recente sem ter falado com ninguém.
     *
     * @return list<array{usuarioId: ?int, usuarioNome: ?string, contatos: int, atendidos: int, acordos: int, baixas: int, ultimaAcao: ?\DateTimeImmutable}>
     */
    public function agregarAtividadePorUsuario(
        Tenant $tenant,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira = null,
    ): array {
        $sql = <<<'SQL'
            SELECT e.usuario_id AS usuario_id,
                   COALESCE(NULLIF(u.full_name, ''), u.email) AS usuario_nome,
                   COUNT(*) FILTER (WHERE e.tipo = :contato) AS contatos,
                   COUNT(*) FILTER (WHERE e.tipo = :contato AND e.dados->>'resultado' = :atendido) AS atendidos,
                   COUNT(*) FILTER (WHERE e.tipo = :acordo) AS acordos,
                   COUNT(*) FILTER (WHERE e.tipo IN (:pagamento, :liquidacao)) AS baixas,
                   MAX(e.ocorrido_em) FILTER (WHERE e.tipo IN (:tiposTrabalho)) AS ultima_acao
            FROM cobranca_evento_historico e
            LEFT JOIN "user" u ON u.id = e.usuario_id
            %s
            WHERE e.tenant_id = :tenant
              AND e.ocorrido_em >= :inicio
              AND e.ocorrido_em < :fim
              %s
            GROUP BY e.usuario_id, u.full_name, u.email
            SQL;

        $consulta = $this->comFiltroDeCarteira(
            $sql,
            $carteira,
            $this->parametrosDoPeriodo($tenant, $inicio, $fimExclusivo) + [
                'contato' => TipoEventoHistorico::ContatoRealizado->value,
                'atendido' => ResultadoContato::Atendido->value,
                'acordo' => TipoEventoHistorico::AcordoCriado->value,
                'pagamento' => TipoEventoHistorico::PagamentoRegistrado->value,
                'liquidacao' => TipoEventoHistorico::LiquidacaoRegistrada->value,
                'tiposTrabalho' => TipoEventoHistorico::valoresDe(TipoEventoHistorico::trabalhoDeCobranca()),
            ],
            ['tiposTrabalho' => ArrayParameterType::STRING],
        );

        $linhas = $this->executar($consulta);

        return array_map(static fn (array $l): array => [
            'usuarioId' => $l['usuario_id'] === null ? null : (int) $l['usuario_id'],
            'usuarioNome' => $l['usuario_nome'] === null ? null : (string) $l['usuario_nome'],
            'contatos' => (int) $l['contatos'],
            'atendidos' => (int) $l['atendidos'],
            'acordos' => (int) $l['acordos'],
            'baixas' => (int) $l['baixas'],
            'ultimaAcao' => $l['ultima_acao'] === null ? null : new \DateTimeImmutable((string) $l['ultima_acao']),
        ], $linhas);
    }

    /**
     * Contatos de UMA pessoa no período, contados por valor CRU de uma chave do payload — `resultado`
     * (desfecho) ou `canal` (meio). A tradução para rótulo é do UseCase; o repositório não conhece
     * labels.
     *
     * A chave vai como PARÂMETRO (`e.dados->>:chave`), não interpolada na SQL: não há string de fora
     * entrando no texto da consulta. O agrupamento usa `GROUP BY 1` (ordinal) porque repetir a
     * expressão no GROUP BY não funciona com parâmetro: o DBAL reescreve o nomeado em posicional, os
     * dois viram placeholders DIFERENTES e o Postgres deixa de reconhecê-los como a mesma expressão.
     *
     * Contato antigo sem a chave (ou com `dados` nulo) cai em `''`; o UseCase o exibe como
     * "Não informado". `$usuarioId` nulo significa a linha "Sem responsável", não "qualquer um".
     *
     * @return array<string, int>
     */
    public function contarPayloadDeContatoDaPessoa(
        Tenant $tenant,
        string $chavePayload,
        ?int $usuarioId,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira = null,
    ): array {
        $extra = $usuarioId === null ? [] : ['usuario' => $usuarioId];

        return $this->contarPayloadDeContato(
            $tenant,
            $chavePayload,
            $usuarioId === null ? 'e.usuario_id IS NULL' : 'e.usuario_id = :usuario',
            $extra,
            $inicio,
            $fimExclusivo,
            $carteira,
        );
    }

    /**
     * Mesma contagem, do SETOR inteiro — a faixa de canais que fica acima da tabela (spec §4). Aqui
     * "todo mundo" inclui os eventos órfãos: a faixa responde "quantos contatos, por qual meio" no
     * período, e o contato existiu mesmo que o autor não esteja mais registrado.
     *
     * @return array<string, int>
     */
    public function contarPayloadDeContatoDoSetor(
        Tenant $tenant,
        string $chavePayload,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira = null,
    ): array {
        return $this->contarPayloadDeContato($tenant, $chavePayload, null, [], $inicio, $fimExclusivo, $carteira);
    }

    /**
     * @param ?string             $condicaoUsuario null = sem recorte de pessoa (setor inteiro)
     * @param array<string, mixed> $extra
     *
     * @return array<string, int>
     */
    private function contarPayloadDeContato(
        Tenant $tenant,
        string $chavePayload,
        ?string $condicaoUsuario,
        array $extra,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira,
    ): array {
        $sql = sprintf(
            <<<'SQL'
                SELECT COALESCE(e.dados->>:chave, '') AS valor, COUNT(*) AS quantidade
                FROM cobranca_evento_historico e
                %%s
                WHERE e.tenant_id = :tenant
                  AND e.tipo = :contato
                  AND e.ocorrido_em >= :inicio
                  AND e.ocorrido_em < :fim
                  %s
                  %%s
                GROUP BY 1
                SQL,
            $condicaoUsuario === null ? '' : 'AND ' . $condicaoUsuario,
        );

        $linhas = $this->executar($this->comFiltroDeCarteira(
            $sql,
            $carteira,
            $this->parametrosDoPeriodo($tenant, $inicio, $fimExclusivo) + $extra + [
                'chave' => $chavePayload,
                'contato' => TipoEventoHistorico::ContatoRealizado->value,
            ],
        ));

        $contagem = [];
        foreach ($linhas as $linha) {
            $contagem[(string) $linha['valor']] = (int) $linha['quantidade'];
        }

        return $contagem;
    }

    /**
     * Eventos de UMA pessoa no período, do mais recente para o mais antigo, RESTRITOS aos tipos pedidos
     * (spec §5.1: o detalhe mostra trabalho de cobrança por padrão, e os lançamentos de
     * cadastro/importação só quando o usuário expande). `$limite` deve vir com a folga de +1 do UseCase:
     * é assim que ele descobre que a lista foi truncada sem uma segunda consulta de contagem.
     *
     * Os joins de caso/objeto são FETCH JOIN de propósito — a lista mostra o objeto de cada evento, e
     * sem eles seriam duas queries por linha. `$usuarioId` nulo = "Sem responsável".
     *
     * @param list<TipoEventoHistorico> $tipos
     *
     * @return EventoHistorico[]
     */
    public function eventosDoUsuarioNoPeriodo(
        Tenant $tenant,
        ?int $usuarioId,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira,
        array $tipos,
        int $limite,
    ): array {
        if ($tipos === []) {
            return [];
        }

        return $this->consultaDeEventos($tenant, $usuarioId, $inicio, $fimExclusivo, $carteira, $tipos)
            ->addSelect('c', 'o')
            ->orderBy('e.ocorridoEm', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Quantos eventos daqueles tipos a pessoa tem no período — é o "+ N lançamentos de
     * cadastro/importação" do detalhe (spec §5.1). Conta no banco; não carrega os eventos para contar.
     *
     * @param list<TipoEventoHistorico> $tipos
     */
    public function contarEventosDoUsuarioNoPeriodo(
        Tenant $tenant,
        ?int $usuarioId,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira,
        array $tipos,
    ): int {
        if ($tipos === []) {
            return 0;
        }

        return (int) $this->consultaDeEventos($tenant, $usuarioId, $inicio, $fimExclusivo, $carteira, $tipos)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Base compartilhada das duas consultas de evento do detalhe — mesmo recorte (tenant, pessoa,
     * período, carteira, tipos), para a contagem nunca discordar da lista.
     *
     * @param list<TipoEventoHistorico> $tipos
     */
    private function consultaDeEventos(
        Tenant $tenant,
        ?int $usuarioId,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira,
        array $tipos,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('e')
            ->join('e.caso', 'c')
            ->join('c.objeto', 'o')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.ocorridoEm >= :inicio')
            ->andWhere('e.ocorridoEm < :fim')
            ->andWhere('e.tipo IN (:tipos)')
            ->setParameter('tenant', $tenant)
            ->setParameter('inicio', $inicio)
            ->setParameter('fim', $fimExclusivo)
            ->setParameter('tipos', TipoEventoHistorico::valoresDe($tipos));

        if ($usuarioId === null) {
            $qb->andWhere('e.usuario IS NULL');
        } else {
            $qb->andWhere('e.usuario = :usuario')->setParameter('usuario', $usuarioId);
        }

        if ($carteira !== null) {
            $qb->andWhere('o.carteira = :carteira')->setParameter('carteira', $carteira);
        }

        return $qb;
    }

    /**
     * @return array<string, int|string>
     */
    private function parametrosDoPeriodo(Tenant $tenant, \DateTimeImmutable $inicio, \DateTimeImmutable $fimExclusivo): array
    {
        return [
            'tenant' => (int) $tenant->getId(),
            'inicio' => $inicio->format('Y-m-d H:i:s'),
            'fim' => $fimExclusivo->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Preenche os dois `%s` dos SQLs acima: o JOIN até a carteira e a condição. Sem filtro de carteira o
     * caminho `evento → caso → objeto` é puro custo, então ele só entra quando é usado.
     *
     * Devolve SQL, parâmetros e tipos JUNTOS, num único valor. A versão anterior mutava `$params` por
     * referência dentro da lista de argumentos do `executeQuery` — funcionava só pela ordem de avaliação
     * do PHP, e qualquer refactor que separasse as linhas quebraria em runtime.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $tipos
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function comFiltroDeCarteira(string $sql, ?Carteira $carteira, array $params, array $tipos = []): array
    {
        if ($carteira === null) {
            return [sprintf($sql, '', ''), $params, $tipos];
        }

        $params['carteira'] = (int) $carteira->getId();

        $sql = sprintf(
            $sql,
            // Tenant repetido nas tabelas juntadas: defesa em profundidade. O recorte já vem do
            // `e.tenant_id` e a carteira é validada por posse no controller, mas esta é a única
            // consulta do arquivo que atravessa três tabelas — barato deixar as três explícitas.
            'INNER JOIN cobranca_caso c ON c.id = e.caso_id AND c.tenant_id = :tenant'
            . ' INNER JOIN cobranca_objeto o ON o.id = c.objeto_id AND o.tenant_id = :tenant',
            'AND o.carteira_id = :carteira',
        );

        return [$sql, $params, $tipos];
    }

    /**
     * @param array{0: string, 1: array<string, mixed>, 2: array<string, mixed>} $consulta
     *
     * @return list<array<string, mixed>>
     */
    private function executar(array $consulta): array
    {
        [$sql, $params, $tipos] = $consulta;

        return $this->getEntityManager()->getConnection()
            ->executeQuery($sql, $params, $tipos)
            ->fetchAllAssociative();
    }
}
