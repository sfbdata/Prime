<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Carteira;
use App\Cliente\Entity\ClientePJ;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @extends ServiceEntityRepository<CasoCobranca>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class CasoCobrancaRepository extends ServiceEntityRepository
{
    private LoggerInterface $logger;

    public function __construct(ManagerRegistry $registry, ?LoggerInterface $logger = null)
    {
        parent::__construct($registry, CasoCobranca::class);
        $this->logger = $logger ?? new NullLogger();
    }

    public function salvar(CasoCobranca $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(CasoCobranca $entidade, bool $flush = false): void
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
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?CasoCobranca
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Casos ATIVOS de um objeto (modo B pode ter vários; SPEC §6). Escopo por tenant do objeto
     * (defesa em profundidade — o objeto já é tenant-bound).
     *
     * @return CasoCobranca[]
     */
    public function casosAtivosDoObjeto(ObjetoCobranca $objeto): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.objeto = :objeto')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.status = :ativo')
            ->setParameter('objeto', $objeto)
            ->setParameter('tenant', $objeto->getTenant())
            ->setParameter('ativo', StatusCaso::Ativo->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * O caso ÂNCORA do objeto para a página unificada (ajuste 2): o ativo mais recente. Se houver >1 ativo
     * (legado do modo B, que permitia vários casos por objeto), escolhe o mais recente e loga um aviso —
     * nunca explode. Se não houver ativo, cai para o mais recente de qualquer status (deep-link de caso já
     * encerrado). null só quando o objeto ainda não tem caso algum. Escopo por tenant do objeto.
     */
    public function casoAncoraDoObjeto(ObjetoCobranca $objeto): ?CasoCobranca
    {
        $ativos = $this->createQueryBuilder('c')
            ->andWhere('c.objeto = :objeto')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.status = :ativo')
            ->setParameter('objeto', $objeto)
            ->setParameter('tenant', $objeto->getTenant())
            ->setParameter('ativo', StatusCaso::Ativo->value)
            ->orderBy('c.criadoEm', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();

        if (count($ativos) > 1) {
            $this->logger->warning('Objeto {objeto} tem {n} casos ativos; usando o mais recente como âncora (ajuste 2).', [
                'objeto' => $objeto->getId(),
                'n' => count($ativos),
            ]);
        }

        if ($ativos !== []) {
            return $ativos[0];
        }

        return $this->createQueryBuilder('c')
            ->andWhere('c.objeto = :objeto')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('objeto', $objeto)
            ->setParameter('tenant', $objeto->getTenant())
            ->orderBy('c.criadoEm', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Há caso ativo para o objeto? Usado pela guarda do modo A (uma cobrança ativa por objeto). */
    public function existeCasoAtivoParaObjeto(ObjetoCobranca $objeto): bool
    {
        $total = (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.objeto = :objeto')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.status = :ativo')
            ->setParameter('objeto', $objeto)
            ->setParameter('tenant', $objeto->getTenant())
            ->setParameter('ativo', StatusCaso::Ativo->value)
            ->getQuery()
            ->getSingleScalarResult();

        return $total > 0;
    }

    /**
     * Lista paginada de casos do tenant, com busca (objeto/pessoa cobrada), faceta de status
     * e faceta de carteira (Etapa 8). Tenant SEMPRE explícito no WHERE. Facetas derivadas do
     * saldo (vencido, pronto p/ encerrar) NÃO entram aqui: o saldo é derivado por
     * `CalculadoraSaldo`, não é coluna — filtro/ordenação por saldo fica p/ a Etapa 9 (dashboard).
     *
     * @param array<string, string> $filtros busca, status, carteira
     * @return CasoCobranca[]
     */
    public function findByFilters(array $filtros, Tenant $tenant, int $pagina, int $porPagina, string $ordenar, string $direcao): array
    {
        $qb = $this->baseFiltro($filtros, $tenant);
        $dir = strtolower($direcao) === 'asc' ? 'ASC' : 'DESC';

        // Fetch-join (addSelect) das associações to-one que o `CasoResumoOutput` hidrata (objeto/carteira/
        // pessoa) — mata o N+1 de hidratação lazy por caso da página. `o`/`p` já vêm de `baseFiltro`. Todas
        // ManyToOne → seguras com `setMaxResults` (não multiplicam linhas, sem paginação em memória).
        $qb->addSelect('o', 'p')
            ->leftJoin('o.carteira', 'cart')
            ->addSelect('cart');

        // COALESCE não é aceito direto no ORDER BY do DQL → alias HIDDEN (não altera o hidratado).
        if ($ordenar === 'criacao') {
            $qb->orderBy('c.criadoEm', $dir);
        } else {
            $qb->addSelect('COALESCE(c.atualizadoEm, c.criadoEm) AS HIDDEN ordCol')->orderBy('ordCol', $dir);
        }

        return $qb
            ->setFirstResult(($pagina - 1) * $porPagina)
            ->setMaxResults($porPagina)
            ->getQuery()
            ->getResult();
    }

    /** @param array<string, string> $filtros */
    public function countByFilters(array $filtros, Tenant $tenant): int
    {
        return (int) $this->baseFiltro($filtros, $tenant)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Casos de uma carteira (mais recentes primeiro) para a visão da carteira (Etapa 8). Escopo por
     * tenant da carteira. Sem paginação — a carteira do MVP tem volume moderado; se crescer, migrar
     * para paginação (follow-up de perf).
     *
     * @return CasoCobranca[]
     */
    /**
     * Credor e devedor das pastas dadas, para a listagem montar o IDENTIFICADOR derivado das pastas
     * judicializadas (spec `pasta-prefixo-do-credor-derivado.md` §5.1).
     *
     * Devolve COLUNAS, não entidades, e resolve a página inteira numa consulta: a alternativa seria
     * um N+1 sobre 50 pastas por página, atravessando quatro tabelas em cada uma.
     *
     * Quem monta a frase é o {@see ComporNomeDaPastaJudicial}; aqui só se busca o dado. A ligação é
     * de MÃO ÚNICA (`CasoCobranca.pastaJudicial` → Pasta), então o caminho é percorrido ao contrário,
     * partindo do caso. Escopo por tenant explícito além do TenantFilter global.
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, array{fantasia: ?string, pessoa: ?string}> indexado pelo id da pasta
     */
    public function credorEDevedorPorPasta(array $pastaIds, Tenant $tenant): array
    {
        if ($pastaIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('c')
            ->select(
                'IDENTITY(c.pastaJudicial) AS pastaId',
                'pj.nomeFantasia AS fantasia',
                'pes.nome AS pessoa',
            )
            ->leftJoin('c.objeto', 'o')
            ->leftJoin('o.carteira', 'ct')
            ->leftJoin('ct.cliente', 'cl')
            // Só ClientePJ tem nome fantasia; o JOIN por id é o mesmo padrão que o PastaRepository
            // já usa para alcançar a subclasse a partir da referência ao Cliente.
            ->leftJoin(ClientePJ::class, 'pj', 'WITH', 'pj.id = cl.id')
            ->leftJoin('c.pessoaCobradaAtual', 'pes')
            ->andWhere('c.pastaJudicial IN (:pastas)')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('pastas', $pastaIds)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getArrayResult();

        $mapa = [];
        foreach ($linhas as $linha) {
            $mapa[(int) $linha['pastaId']] = [
                'fantasia' => $linha['fantasia'] !== null ? (string) $linha['fantasia'] : null,
                'pessoa' => $linha['pessoa'] !== null ? (string) $linha['pessoa'] : null,
            ];
        }

        return $mapa;
    }

    public function daCarteira(Carteira $carteira): array
    {
        // Fetch-join (addSelect) de objeto/carteira/pessoa: o `CasoResumoOutput` monta a linha a partir
        // dessas associações to-one; sem o fetch-join cada caso dispararia hidratação lazy (a pessoa é
        // ~distinta por caso → N+1). ManyToOne → não multiplicam linhas; sem paginação aqui → seguro.
        return $this->createQueryBuilder('c')
            ->innerJoin('c.objeto', 'o')
            ->addSelect('o')
            ->leftJoin('o.carteira', 'cart')
            ->addSelect('cart')
            ->leftJoin('c.pessoaCobradaAtual', 'p')
            ->addSelect('p')
            ->addSelect('COALESCE(c.atualizadoEm, c.criadoEm) AS HIDDEN ordCol')
            ->andWhere('o.carteira = :carteira')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $carteira->getTenant())
            ->orderBy('ordCol', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * IDs dos casos da carteira que casam com a busca livre da página da carteira — identificação
     * ou descrição do OBJETO, ou nome da PESSOA COBRADA. `UNACCENT(LOWER(...))` dos dois lados
     * (mesma receita do Expediente, `PastaRepository::aplicarFiltros`): "joao" acha "João" e
     * vice-versa.
     *
     * Devolve só IDs de propósito: a página precisa do saldo consolidado da carteira INTEIRA no
     * cabeçalho — que é derivado (`CalculadoraSaldo`), não coluna, e por isso exige carregar todos
     * os casos. Filtrar o `daCarteira()` arrastaria o cabeçalho junto; esta consulta é rasa e só
     * roda quando há busca. Escopo por tenant da carteira, como todo o resto.
     *
     * @return array<int, true> id do caso => true (conjunto de presença)
     */
    public function idsDaCarteiraCasandoBusca(Carteira $carteira, string $busca): array
    {
        $termo = trim($busca);
        if ($termo === '') {
            return [];
        }

        // leftJoin na pessoa (não inner): mantém a mesma semântica de junção do `daCarteira`, e caso
        // sem pessoa simplesmente não casa pelo nome.
        $linhas = $this->createQueryBuilder('c')
            ->select('c.id')
            ->innerJoin('c.objeto', 'o')
            ->leftJoin('c.pessoaCobradaAtual', 'p')
            ->andWhere('o.carteira = :carteira')
            ->andWhere('c.tenant = :tenant')
            ->andWhere(
                'UNACCENT(LOWER(o.identificacao)) LIKE UNACCENT(:busca)'
                . ' OR UNACCENT(LOWER(o.descricao)) LIKE UNACCENT(:busca)'
                . ' OR UNACCENT(LOWER(p.nome)) LIKE UNACCENT(:busca)'
            )
            ->setParameter('carteira', $carteira)
            ->setParameter('tenant', $carteira->getTenant())
            ->setParameter('busca', '%' . mb_strtolower($termo) . '%')
            ->getQuery()
            ->getResult();

        $ids = [];
        foreach ($linhas as $linha) {
            $ids[(int) $linha['id']] = true;
        }

        return $ids;
    }

    /**
     * TODOS os casos do tenant (opcionalmente de uma carteira), mais recentes primeiro — base da
     * agregação tenant-wide do Dashboard e da Central de Alertas (Etapa 9). Tenant SEMPRE explícito no
     * WHERE. Inclui casos encerrados de propósito: o "valor total recuperado" (all-time) precisa deles;
     * a derivação por-caso naturalmente zera saldo/alertas do encerrado. Sem paginação — o Dashboard
     * consome tudo; se o volume crescer, migrar para agregação materializada (follow-up de perf, coerente
     * com a nota do `findByFilters`). Saldo/alertas continuam derivados por serviço, nunca por SQL aqui.
     *
     * @return CasoCobranca[]
     */
    public function doTenant(Tenant $tenant, ?Carteira $carteira = null): array
    {
        // Fetch-join (addSelect) de objeto/carteira/pessoa: os consumidores tenant-wide (Central de Alertas,
        // Dashboard) montam Output DTOs a partir dessas associações to-one; sem o fetch-join cada caso
        // dispararia a hidratação lazy (N+1). São ManyToOne → não multiplicam linhas nem afetam a ordenação.
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.objeto', 'o')
            ->addSelect('o')
            ->leftJoin('o.carteira', 'cart')
            ->addSelect('cart')
            ->leftJoin('c.pessoaCobradaAtual', 'p')
            ->addSelect('p')
            ->addSelect('COALESCE(c.atualizadoEm, c.criadoEm) AS HIDDEN ordCol')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('ordCol', 'DESC');

        if ($carteira !== null) {
            $qb->andWhere('o.carteira = :carteira')->setParameter('carteira', $carteira);
        }

        return $qb->getQuery()->getResult();
    }

    /** @param array<string, string> $filtros */
    private function baseFiltro(array $filtros, Tenant $tenant): QueryBuilder
    {
        // INNER JOIN é seguro: objeto e pessoaCobradaAtual são JoinColumn(nullable: false) —
        // um caso persistido sempre tem ambos (o `?` no type-hint é só o estado pré-persist).
        $qb = $this->createQueryBuilder('c')
            ->innerJoin('c.objeto', 'o')
            ->innerJoin('c.pessoaCobradaAtual', 'p')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $busca = trim($filtros['busca'] ?? '');
        if ($busca !== '') {
            $qb->andWhere('LOWER(o.identificacao) LIKE :busca OR LOWER(o.descricao) LIKE :busca OR LOWER(p.nome) LIKE :busca')
                ->setParameter('busca', '%' . mb_strtolower($busca) . '%');
        }

        $status = $filtros['status'] ?? '';
        if ($status !== '') {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }

        $carteira = $filtros['carteira'] ?? '';
        if ($carteira !== '' && ctype_digit($carteira)) {
            $qb->andWhere('o.carteira = :carteira')->setParameter('carteira', (int) $carteira);
        }

        return $qb;
    }
}
