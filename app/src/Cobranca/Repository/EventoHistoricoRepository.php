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
            ->orderBy('e.ocorridoEm', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
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
                   MAX(e.ocorrido_em) AS ultima_acao
            FROM cobranca_evento_historico e
            LEFT JOIN "user" u ON u.id = e.usuario_id
            %s
            WHERE e.tenant_id = :tenant
              AND e.ocorrido_em >= :inicio
              AND e.ocorrido_em < :fim
              %s
            GROUP BY e.usuario_id, u.full_name, u.email
            SQL;

        $params = $this->parametrosDoPeriodo($tenant, $inicio, $fimExclusivo) + [
            'contato' => TipoEventoHistorico::ContatoRealizado->value,
            'atendido' => ResultadoContato::Atendido->value,
            'acordo' => TipoEventoHistorico::AcordoCriado->value,
            'pagamento' => TipoEventoHistorico::PagamentoRegistrado->value,
            'liquidacao' => TipoEventoHistorico::LiquidacaoRegistrada->value,
        ];

        $linhas = $this->getEntityManager()->getConnection()
            ->executeQuery($this->comFiltroDeCarteira($sql, $carteira, $params), $params)
            ->fetchAllAssociative();

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
     * Desfechos dos contatos de UMA pessoa no período, contados por valor CRU do payload
     * (`dados->>'resultado'`). A tradução para rótulo é do UseCase — o repositório não conhece labels.
     *
     * Contato antigo sem a chave `resultado` (ou com `dados` nulo) cai na chave `''`; o UseCase o exibe
     * como "Não informado". `$usuarioId` nulo significa a linha "Sem responsável", não "qualquer um".
     *
     * @return array<string, int>
     */
    public function contarDesfechosDeContato(
        Tenant $tenant,
        ?int $usuarioId,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira = null,
    ): array {
        $condicaoUsuario = $usuarioId === null ? 'e.usuario_id IS NULL' : 'e.usuario_id = :usuario';

        $sql = sprintf(
            <<<'SQL'
                SELECT COALESCE(e.dados->>'resultado', '') AS resultado, COUNT(*) AS quantidade
                FROM cobranca_evento_historico e
                %%s
                WHERE e.tenant_id = :tenant
                  AND e.tipo = :contato
                  AND e.ocorrido_em >= :inicio
                  AND e.ocorrido_em < :fim
                  AND %s
                  %%s
                GROUP BY COALESCE(e.dados->>'resultado', '')
                SQL,
            $condicaoUsuario,
        );

        $params = $this->parametrosDoPeriodo($tenant, $inicio, $fimExclusivo) + [
            'contato' => TipoEventoHistorico::ContatoRealizado->value,
        ];
        if ($usuarioId !== null) {
            $params['usuario'] = $usuarioId;
        }

        $linhas = $this->getEntityManager()->getConnection()
            ->executeQuery($this->comFiltroDeCarteira($sql, $carteira, $params), $params)
            ->fetchAllAssociative();

        $contagem = [];
        foreach ($linhas as $linha) {
            $contagem[(string) $linha['resultado']] = (int) $linha['quantidade'];
        }

        return $contagem;
    }

    /**
     * Eventos de UMA pessoa no período, do mais recente para o mais antigo. `$limite` deve vir com a
     * folga de +1 do UseCase: é assim que ele descobre que a lista foi truncada sem uma segunda consulta
     * de contagem.
     *
     * Os joins de caso/objeto são FETCH JOIN de propósito — a lista mostra o objeto de cada evento, e
     * sem eles seriam duas queries por linha. `$usuarioId` nulo = "Sem responsável".
     *
     * @return EventoHistorico[]
     */
    public function eventosDoUsuarioNoPeriodo(
        Tenant $tenant,
        ?int $usuarioId,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fimExclusivo,
        ?Carteira $carteira,
        int $limite,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->addSelect('c', 'o')
            ->join('e.caso', 'c')
            ->join('c.objeto', 'o')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.ocorridoEm >= :inicio')
            ->andWhere('e.ocorridoEm < :fim')
            ->setParameter('tenant', $tenant)
            ->setParameter('inicio', $inicio)
            ->setParameter('fim', $fimExclusivo)
            ->orderBy('e.ocorridoEm', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limite);

        if ($usuarioId === null) {
            $qb->andWhere('e.usuario IS NULL');
        } else {
            $qb->andWhere('e.usuario = :usuario')->setParameter('usuario', $usuarioId);
        }

        if ($carteira !== null) {
            $qb->andWhere('o.carteira = :carteira')->setParameter('carteira', $carteira);
        }

        return $qb->getQuery()->getResult();
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
     * @param array<string, mixed> $params preenchido por referência com o id da carteira
     */
    private function comFiltroDeCarteira(string $sql, ?Carteira $carteira, array &$params): string
    {
        if ($carteira === null) {
            return sprintf($sql, '', '');
        }

        $params['carteira'] = (int) $carteira->getId();

        return sprintf(
            $sql,
            'INNER JOIN cobranca_caso c ON c.id = e.caso_id INNER JOIN cobranca_objeto o ON o.id = c.objeto_id',
            'AND o.carteira_id = :carteira',
        );
    }
}
