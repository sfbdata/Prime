<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Enum\StatusCaso;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Obrigacao>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class ObrigacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Obrigacao::class);
    }

    public function salvar(Obrigacao $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(Obrigacao $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?Obrigacao
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Obrigação de um caso por sua referência externa (o NN do boleto na importação — decisão C).
     * Chave de idempotência: reimportar o mesmo boleto ATUALIZA em vez de duplicar. Escopo por tenant
     * do caso (defesa em profundidade). A referência é comparada aparada; null/'' nunca casa.
     */
    public function findOnePorReferenciaExternaNoCaso(CasoCobranca $caso, string $referenciaExterna): ?Obrigacao
    {
        $referencia = trim($referenciaExterna);
        if ($referencia === '') {
            return null;
        }

        return $this->findOneBy([
            'caso' => $caso,
            'tenant' => $caso->getTenant(),
            'referenciaExterna' => $referencia,
        ]);
    }

    /**
     * A coluna `competencia` existe no banco? (`Version20260730120000`).
     *
     * Existe para o importador poder RECUSAR com uma frase clara em vez de morrer no meio do lote. Sem a
     * migration, o primeiro INSERT estoura com erro de SQL, a transação faz rollback e o operador recebe
     * um traço de driver — nada indica que o que falta é rodar a migration. Consulta barata, uma vez por
     * execução, no `information_schema` (SQL padrão).
     */
    public function schemaTemCompetencia(): bool
    {
        $sql = 'SELECT 1 FROM information_schema.columns WHERE table_name = :tabela AND column_name = :coluna';

        return $this->getEntityManager()->getConnection()
            ->fetchOne($sql, ['tabela' => 'cobranca_obrigacao', 'coluna' => 'competencia']) !== false;
    }

    /**
     * Chave de idempotência da importação: (caso, NN, competência) — spec
     * `cobranca-importar-chave-competencia.md`. O NN sozinho NÃO identifica a dívida: a contábil o
     * reaproveita (NN 61457 = taxa de 08/2022 na TOP LIFE I e de 07/2026 na TOP LIFE 2). Casar só por NN
     * faz o importador ENGOLIR o boleto novo e gravar os encargos dele sobre a dívida antiga.
     *
     * O vencimento NÃO serve como discriminador: reemissão para o devedor pagar mantém o NN e muda a data.
     *
     * Ordem de busca:
     *   1. par exato (NN + competência) — o caso normal;
     *   2. se o relatório não trouxe competência, ou se existe obrigação com esse NN e competência NULA
     *      (dado legado, anterior ao backfill, ou cadastro manual), casa só pelo NN — comportamento antigo,
     *      preservado para não duplicar dado legado.
     *
     * Retorna null quando existe obrigação com o mesmo NN mas competência DIFERENTE e não-nula: é outra
     * dívida, e o chamador deve criar uma nova (reportando o NN reutilizado).
     *
     * ⚠️ **O passo 2 é, literalmente, casar pelo NN sozinho** — o casamento que a spec manda desconfiar.
     * Ele existe porque a alternativa é pior: devolver null aqui faz o chamador CRIAR a obrigação, e a
     * primeira reimportação após o deploy duplicaria todo dado que o backfill não alcançou. Duas defesas
     * o cercam: o `ImportarAcordosDetalhadosUseCase` **reporta** cada casamento assim
     * (`casadasSemCompetencia`), e ele só alcança obrigação sem competência — medido em produção em
     * 31/07: **zero linhas**, porque as 21 sem competência também não têm NN.
     *
     * O desempate é por **id ASC**: com mais de uma candidata legada (mesmo NN, competência nula, mesmo
     * caso), a escolha precisa ser a mesma em toda execução. `findOneBy` sem ordem deixaria isso a cargo
     * do plano do banco — duas rodadas do mesmo arquivo poderiam atualizar obrigações diferentes.
     */
    public function findOnePorReferenciaECompetenciaNoCaso(
        CasoCobranca $caso,
        string $referenciaExterna,
        ?string $competencia,
    ): ?Obrigacao {
        $referencia = trim($referenciaExterna);
        if ($referencia === '') {
            return null;
        }

        $competencia = $competencia === null ? null : trim($competencia);
        $base = [
            'caso' => $caso,
            'tenant' => $caso->getTenant(),
            'referenciaExterna' => $referencia,
        ];

        if ($competencia !== null && $competencia !== '') {
            $exata = $this->findOneBy($base + ['competencia' => $competencia], ['id' => 'ASC']);
            if ($exata !== null) {
                return $exata;
            }
        }

        // Fallback do legado: obrigação com o mesmo NN e SEM competência gravada. Sem isto, a primeira
        // reimportação após o deploy duplicaria toda obrigação que o backfill não alcançou. Ver o
        // docblock: é casamento por NN sozinho, de propósito, e o chamador reporta.
        return $this->findOneBy($base + ['competencia' => null], ['id' => 'ASC']);
    }

    /**
     * Obrigações de um caso (fonte do saldo derivado — SPEC §10, invariável 20). Escopo por
     * tenant do caso (defesa em profundidade).
     *
     * @return Obrigacao[]
     */
    public function doCaso(CasoCobranca $caso): array
    {
        return $this->createQueryBuilder('o')
            // Fetch-join dos acordos: o ObrigacaoOutput lê o STATUS dos dois (substituída/parcela/acordo
            // desfeito) e o agrupamento da aba lê o id da origem — sem isto, cada obrigação ligada a um
            // acordo dispara um lazy-load (N+1). leftJoin+addSelect não filtra linha nenhuma.
            ->leftJoin('o.acordoOrigem', 'aorig')->addSelect('aorig')
            ->leftJoin('o.acordoSubstituto', 'asub')->addSelect('asub')
            ->andWhere('o.caso = :caso')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant())
            ->orderBy('o.vencimentoOriginal', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Obrigações EXIGÍVEIS do caso (fonte do saldo — SPEC §12, invariável 15): exclui as substituídas
     * por um acordo VIGENTE (ativo/cumprido) e as parcelas de um acordo NÃO vigente (rompido/cancelado).
     * Assim, romper/cancelar um acordo restaura os originais e descarta as parcelas por derivação
     * (invariável 20). Parênteses explícitos: `andWhere` junta com AND sem parentetizar o OR.
     *
     * @return Obrigacao[]
     */
    public function doCasoExigiveis(CasoCobranca $caso): array
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.caso = :caso')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('caso', $caso)
            ->setParameter('tenant', $caso->getTenant());

        return $this->aplicarExigibilidade($qb)
            ->orderBy('o.vencimentoOriginal', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * As obrigações da carteira que estão TOTALMENTE PAGAS mas não marcadas como liquidadas —
     * o balde do D12 (SPEC espelho §5.1).
     *
     * O sistema tem duas réguas de "pago" e elas **discordam em produção**: medido em 12/08 **no
     * banco de PRODUÇÃO (`prime`), pelo MCP de leitura**, 11 obrigações na TOP LIFE I e 2 na TOP
     * LIFE II estão com alocação suficiente e `liquidadaEm` nulo (zero no sentido inverso). A
     * conferência usa `liquidadaEm` como universo e reporta estas em balde próprio: decidir em
     * silêncio por uma das duas esconderia uma inconsistência interna real.
     *
     * ⚠️ **No dev isso dá ZERO**, e não é defeito da consulta: o dev está com dado de 11/07 e nenhuma
     * das alocações dele pertence a obrigação em aberto. Quem for conferir o número precisa fazê-lo
     * em produção — foi o que a revisão desta fatia levantou, com razão.
     *
     * A régua reproduz `ObrigacaoOutput::quitada()`: `alocado >= valorExigivel()`, que é
     * `valorOriginal + juros + multa + correcao` — honorários ficam de fora (INV-E2).
     *
     * @return list<array{id: int, unidade: string, referenciaExterna: ?string, competencia: ?string}>
     */
    public function pagasMasNaoLiquidadasDaCarteira(Carteira $carteira): array
    {
        /** @var list<array{id: int, unidade: string, referenciaExterna: ?string, competencia: ?string}> $linhas */
        $linhas = $this->createQueryBuilder('o')
            ->select(
                'o.id AS id',
                'obj.identificacao AS unidade',
                'o.referenciaExterna AS referenciaExterna',
                'o.competencia AS competencia',
            )
            ->join('o.caso', 'c')
            ->join('c.objeto', 'obj')
            // O tenant entra na CONDIÇÃO do join, não só no WHERE: sem isso uma alocação de outro
            // escritório entraria no SUM e a obrigação pareceria paga. É soma de dinheiro.
            ->leftJoin(AlocacaoPagamento::class, 'a', Join::ON, 'a.obrigacao = o AND a.tenant = :tenant')
            ->andWhere('obj.carteira = :carteira')
            ->andWhere('o.tenant = :tenant')
            ->andWhere('o.liquidadaEm IS NULL')
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $carteira->getTenant())
            ->groupBy('o.id', 'obj.identificacao', 'o.referenciaExterna', 'o.competencia',
                'o.valorOriginal', 'o.juros', 'o.multa', 'o.correcao')
            ->having('COALESCE(SUM(a.valor), 0) >= o.valorOriginal + o.juros + o.multa + o.correcao')
            ->getQuery()
            ->getResult();

        return $linhas;
    }

    /**
     * O predicado CANÔNICO de exigibilidade, num lugar só.
     *
     * Exclui as obrigações substituídas por um acordo VIGENTE (ativo/cumprido) e as parcelas de um
     * acordo NÃO vigente (rompido/cancelado). Assim, romper ou cancelar um acordo restaura os
     * originais e descarta as parcelas por derivação (invariável 20). Os parênteses são explícitos
     * porque `andWhere` junta com AND sem parentetizar o OR.
     *
     * ⚠️ **"Exigível" NÃO quer dizer "em aberto":** este conjunto inclui a obrigação já quitada e a
     * parcela de acordo CUMPRIDO (ver `AlertasCobranca`). Quem precisa só do que está em aberto tem
     * de somar `liquidadaEm IS NULL` por fora — é uma condição da PERGUNTA, não da exigibilidade.
     *
     * Extraído em 12/08 (spec `cobranca-espelho-da-contabilidade.md`, D10): as mesmas seis linhas
     * estavam copiadas verbatim em `doCasoExigiveis` e `exigiveisDosCasos`, e a conferência do
     * espelho seria a terceira cópia. Regra de dinheiro duplicada diverge em silêncio.
     */
    private function aplicarExigibilidade(QueryBuilder $qb, string $alias = 'o'): QueryBuilder
    {
        return $qb
            ->leftJoin($alias . '.acordoSubstituto', 'asub')
            ->leftJoin($alias . '.acordoOrigem', 'aorig')
            ->andWhere('(asub.id IS NULL OR asub.status IN (:naoVigentes))')
            ->andWhere('(aorig.id IS NULL OR aorig.status IN (:vigentes))')
            ->setParameter('naoVigentes', [StatusAcordo::Rompido->value, StatusAcordo::Cancelado->value])
            ->setParameter('vigentes', [StatusAcordo::Ativo->value, StatusAcordo::Cumprido->value]);
    }

    /**
     * Obrigações que um acordo NOVO pode substituir: só DÍVIDAS ORIGINAIS (ajuste 9, INV-I). É a lista que
     * a tela de criar acordo oferece — NÃO é regra de saldo.
     *
     * Reusa `doCasoExigiveis` de propósito: os critérios de exigibilidade não podem divergir em dois
     * lugares. Dentro do conjunto exigível, toda parcela é necessariamente de um acordo VIGENTE (garantia
     * da cláusula `aorig.status IN (:vigentes)` acima), logo "excluir parcela de acordo vigente" equivale
     * aqui a `acordoOrigem === null`.
     *
     * Por que existe: substituir a parcela de um acordo ainda vigente (acordo sobre acordo) duplica a
     * dívida no saldo quando o acordo de origem é rompido/cancelado — a original volta ao exigível E as
     * parcelas do acordo novo continuam nele. Renegociar um acordo se faz rompendo-o primeiro.
     *
     * @return list<Obrigacao>
     */
    public function doCasoSubstituiveis(CasoCobranca $caso): array
    {
        return array_values(array_filter(
            $this->doCasoExigiveis($caso),
            static fn (Obrigacao $obrigacao): bool => $obrigacao->getAcordoOrigem() === null,
        ));
    }

    /**
     * Parcelas DESTE acordo que um OUTRO acordo VIGENTE substituiu como dívida original — o estado
     * "acordo sobre acordo" (ajuste 9 §2.1). Vazio = o acordo pode ser rompido/cancelado sem duplicar
     * dívida no saldo.
     *
     * Existe porque romper/cancelar um acordo cujas parcelas outro acordo renegociou faz a dívida entrar
     * DUAS vezes no exigível: as originais que este acordo substituiu voltam E as parcelas do acordo novo
     * continuam. Com a criação bloqueada (INV-I) isto só ocorre em dado legado — é um alarme.
     *
     * Query única e tenant-scoped de propósito: iterar `Acordo::getParcelas()` custaria um lazy load da
     * coleção + um do `acordoSubstituto` de cada parcela.
     *
     * @return list<Obrigacao>
     */
    public function parcelasRenegociadasPorAcordoVigente(Acordo $acordo): array
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.acordoSubstituto', 'asub')
            ->andWhere('o.acordoOrigem = :acordo')
            ->andWhere('o.tenant = :tenant')
            ->andWhere('asub.status IN (:vigentes)')
            ->setParameter('acordo', $acordo)
            ->setParameter('tenant', $acordo->getTenant())
            ->setParameter('vigentes', [StatusAcordo::Ativo->value, StatusAcordo::Cumprido->value])
            ->orderBy('o.vencimentoOriginal', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * As obrigações ORIGINAIS que este acordo substituiu — o conjunto que o rompimento/cancelamento
     * precisa descongelar (spec `cobranca-cancelar-acordo.md` §D5).
     *
     * 🔑 **Existe para NÃO depender de `Acordo::getObrigacoesSubstituidas()`**, que é o lado INVERSO da
     * associação. Quando o acordo foi criado na MESMA unidade de trabalho (`CriarAcordoUseCase` só
     * escreve o lado dono, `Obrigacao::setAcordoSubstituto`), a coleção inversa continua vazia e o laço
     * de restauração não descongela nada — em silêncio. Em produção o cancelamento é outro request e a
     * coleção carrega do banco, o que faz o defeito passar despercebido justamente onde há teste.
     * Uma query explícita dá a mesma resposta nos dois casos.
     *
     * Tenant EXPLÍCITO, e não `$acordo->getTenant()`: aquele é nullable, e um tenant nulo devolveria
     * lista vazia **em silêncio** — exatamente o modo de falha que esta query existe para eliminar.
     *
     * @return Obrigacao[]
     */
    public function substituidasPorAcordo(Acordo $acordo, Tenant $tenant): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.acordoSubstituto = :acordo')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('acordo', $acordo)
            ->setParameter('tenant', $tenant)
            ->orderBy('o.vencimentoOriginal', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * As PARCELAS geradas por este acordo. Mesmo motivo de existir que `substituidasPorAcordo`: não
     * depender da coleção inversa `Acordo::getParcelas()`, que nasce vazia quando o acordo foi criado
     * na mesma unidade de trabalho. Aqui a consequência seria pior — a guarda de pagamento do
     * cancelamento (§D4) receberia uma lista vazia e deixaria passar um acordo com dinheiro recebido.
     *
     * Tenant EXPLÍCITO pelo mesmo motivo de `substituidasPorAcordo`: lista vazia por tenant nulo aqui
     * significaria a guarda de pagamento deixando passar um acordo com dinheiro recebido.
     *
     * @return Obrigacao[]
     */
    public function parcelasDoAcordo(Acordo $acordo, Tenant $tenant): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.acordoOrigem = :acordo')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('acordo', $acordo)
            ->setParameter('tenant', $tenant)
            ->orderBy('o.vencimentoOriginal', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Versão em LOTE de `doCasoExigiveis` para a agregação tenant-wide (Dashboard, Etapa 9): as obrigações
     * EXIGÍVEIS de VÁRIOS casos numa única query, com o MESMO filtro de exclusão (substituídas por acordo
     * vigente / parcelas de acordo rompido-cancelado — SPEC §12, invariáveis 15/20). Evita o N+1 de chamar
     * `doCasoExigiveis` por caso. Tenant SEMPRE explícito. `$casoIds` vazio → `[]` (sem query).
     *
     * @param int[] $casoIds
     *
     * @return Obrigacao[]
     */
    public function exigiveisDosCasos(array $casoIds, Tenant $tenant): array
    {
        if ($casoIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.caso IN (:casos)')
            ->andWhere('o.tenant = :tenant')
            ->setParameter('casos', $casoIds)
            ->setParameter('tenant', $tenant);

        return $this->aplicarExigibilidade($qb)
            ->orderBy('o.vencimentoOriginal', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * O MESMO universo de {@see self::emAbertoDaCarteiraAte}, mas em ENTIDADES — para a régua do
     * encargo gravado (spec `cobranca-espelho-da-contabilidade.md` §17).
     *
     * Existe separado, e não como um flag do irmão, porque as duas perguntas precisam de coisas
     * diferentes: a conferência precisa da chave e do principal (linhas cruas bastam, e são milhares);
     * esta precisa da entidade inteira, porque a configuração efetiva sai da cascata
     * (`ResolvedorConfigEncargos`), e reimplementar a cascata a partir de colunas seria uma segunda
     * cópia de regra de dinheiro — exatamente o que o D10 proíbe.
     *
     * ⚠️ `caso` e `objeto` vêm por **fetch join** de propósito. Sem eles, resolver a cascata de cada
     * obrigação dispara dois lazy-loads por linha, e o comando roda em console sobre milhares de
     * obrigações — é o N+1 que já derrubou a calibração por falta de memória.
     *
     * @return list<Obrigacao>
     */
    public function emAbertoDaCarteiraAteComEncargos(Carteira $carteira, \DateTimeImmutable $dadosAte): array
    {
        $qb = $this->createQueryBuilder('o')
            ->addSelect('c', 'obj')
            ->join('o.caso', 'c')
            ->join('c.objeto', 'obj')
            ->andWhere('obj.carteira = :carteira')
            // Tenant EXPLÍCITO: o `TenantFilter` só liga no evento de request, e isto roda em console.
            ->andWhere('o.tenant = :tenant')
            ->andWhere('o.liquidadaEm IS NULL')
            ->andWhere('o.vencimentoOriginal <= :dadosAte')
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $carteira->getTenant())
            ->setParameter('dadosAte', $dadosAte);

        /** @var list<Obrigacao> $obrigacoes */
        $obrigacoes = $this->aplicarExigibilidade($qb)->getQuery()->getResult();

        return $obrigacoes;
    }

    /**
     * O que o SISTEMA considera devido numa carteira até uma data — o universo da conferência do
     * espelho (spec `cobranca-espelho-da-contabilidade.md` §5.1).
     *
     * São TRÊS condições independentes, e a spec exige que fiquem separadas no código:
     *  1. **exigibilidade** — regra do sistema, reusada de `aplicarExigibilidade` (D10);
     *  2. **em aberto** (`liquidadaEm IS NULL`) — recorte da conferência, não da exigibilidade;
     *  3. **vencida até `$dadosAte`** — recorte para casar com o escopo do relatório.
     *
     * Medido em produção em 12/08: sem a condição 2 a TOP LIFE I saltaria de 3.099 para 10.576
     * obrigações — 7.477 divergências falsas.
     *
     * Devolve linhas cruas (não entidades): são milhares, e a conferência só precisa da chave, do
     * principal e de saber se é parcela de acordo.
     *
     * @return list<array{id: int, casoId: int, referenciaExterna: ?string, competencia: ?string,
     *                    valorOriginal: int, unidade: string, ehParcelaDeAcordo: bool}>
     */
    public function emAbertoDaCarteiraAte(Carteira $carteira, \DateTimeImmutable $dadosAte): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select(
                'o.id AS id',
                'IDENTITY(o.caso) AS casoId',
                'o.referenciaExterna AS referenciaExterna',
                'o.competencia AS competencia',
                'o.valorOriginal AS valorOriginal',
                'obj.identificacao AS unidade',
                'IDENTITY(o.acordoOrigem) AS acordoOrigemId',
            )
            ->join('o.caso', 'c')
            ->join('c.objeto', 'obj')
            ->andWhere('obj.carteira = :carteira')
            // Tenant EXPLÍCITO: o `TenantFilter` só é ligado no evento de request, e esta consulta
            // roda em comando de console, onde ele nunca liga. Era o único método deste repositório
            // sem o filtro próprio.
            ->andWhere('o.tenant = :tenant')
            ->andWhere('o.liquidadaEm IS NULL')
            ->andWhere('o.vencimentoOriginal <= :dadosAte')
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $carteira->getTenant())
            ->setParameter('dadosAte', $dadosAte);

        /** @var list<array<string, mixed>> $linhas */
        $linhas = $this->aplicarExigibilidade($qb)->getQuery()->getResult();

        return array_map(
            static fn (array $l): array => [
                'id' => (int) $l['id'],
                'casoId' => (int) $l['casoId'],
                'referenciaExterna' => $l['referenciaExterna'],
                'competencia' => $l['competencia'],
                'valorOriginal' => (int) $l['valorOriginal'],
                'unidade' => (string) $l['unidade'],
                'ehParcelaDeAcordo' => $l['acordoOrigemId'] !== null,
            ],
            $linhas,
        );
    }

    /**
     * Obrigações de UM caso candidatas ao recálculo imediato de encargos (Ajuste 2, Fatia A): NÃO
     * congeladas (`encargosCongeladosEm IS NULL`), de caso NÃO encerrado (SPEC §17), exigíveis que NÃO
     * sejam parcela de acordo (`aorig.id IS NULL`) nem substituídas por acordo VIGENTE (`asub.id IS NULL
     * OR asub.status IN (:naoVigentes)`) — ESCOPADO ao caso (`o.caso = :caso`).
     *
     * Devolve ENTIDADES managed: o chamador (`EditarConfiguracaoCasoUseCase`) recalcula e flusha na
     * MESMA unidade de trabalho, então precisa dos objetos gerenciados. NÃO usa `doCasoExigiveis` de
     * propósito — aquele inclui a parcela de acordo VIGENTE, que aqui se EXCLUI: valor pactuado não
     * recebe mora automática.
     *
     * Tenant do caso SEMPRE explícito (defesa em profundidade). Ordem estável por id.
     *
     * @return list<Obrigacao>
     */
    public function paraRecalculoDeEncargosDoCaso(CasoCobranca $caso): array
    {
        return $this->createQueryBuilder('o')
            ->innerJoin('o.caso', 'c')
            ->leftJoin('o.acordoSubstituto', 'asub')
            ->leftJoin('o.acordoOrigem', 'aorig')
            ->andWhere('o.caso = :caso')
            ->andWhere('o.encargosCongeladosEm IS NULL')
            ->andWhere('c.status != :encerrado')
            ->andWhere('o.tenant = :tenant')
            // Parênteses explícitos: `andWhere` junta com AND sem parentetizar o OR.
            ->andWhere('(asub.id IS NULL OR asub.status IN (:naoVigentes))')
            ->andWhere('aorig.id IS NULL')
            ->setParameter('caso', $caso)
            ->setParameter('encerrado', StatusCaso::Encerrado->value)
            ->setParameter('tenant', $caso->getTenant())
            ->setParameter('naoVigentes', [StatusAcordo::Rompido->value, StatusAcordo::Cancelado->value])
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * As contas originais RECONSTRUÍDAS da planilha de acordos que ficaram sem o override de honorário
     * — a população da correção da spec `cobranca-honorario-no-total.md` §10.
     *
     * 🔑 **A marca de procedência é a chave, e não "parcela sem override".** As três condições abaixo
     * não são intercambiáveis: `acordoOrigem IS NOT NULL` + `taxaHonorariosBp IS NULL` também casaria
     * uma AVULSA apenas vinculada a um acordo, cujo `valorOriginal` é `principalCentavos` e **não**
     * contém o honorário. Zerar a taxa dela removeria cobrança legítima e, pior, mudaria a alocação da
     * importação de receitas, que lê `taxaHonorariosBp === 0` como "o honorário já está no valor"
     * (`ImportarReceitasUseCase`). O que autoriza a correção é a procedência: só a conta reconstruída
     * nasce com o boleto SOMADO (`AcordosDetalhadosAdapter::montarContaOriginal`), honorário dentro.
     *
     * Medido em produção em 19/08: 135 obrigações, 135 com a marca — nenhuma avulsa vinculada existe.
     *
     * Devolve ENTIDADES managed: o chamador corrige e flusha na mesma unidade de trabalho.
     * Tenant EXPLÍCITO — isto roda em console, onde o `TenantFilter` nunca liga.
     *
     * @return list<Obrigacao>
     */
    public function reconstruidasSemOverrideDeHonorario(Carteira $carteira, Tenant $tenant, string $marca): array
    {
        return $this->createQueryBuilder('o')
            ->join('o.caso', 'c')
            ->join('c.objeto', 'obj')
            ->andWhere('obj.carteira = :carteira')
            ->andWhere('o.tenant = :tenant')
            ->andWhere('o.acordoOrigem IS NOT NULL')
            ->andWhere('o.taxaHonorariosBp IS NULL')
            ->andWhere('o.descricao LIKE :marca')
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $tenant)
            ->setParameter('marca', '%' . $marca . '%')
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
