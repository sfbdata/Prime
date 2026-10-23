<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CorrecaoDeValorDoPagamentoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Entity\TipoDePagamento;
use App\Pasta\Service\LancamentosDaPastaSobTrava;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Proxy;

/**
 * @extends ServiceEntityRepository<PastaPagamento>
 */
final class PastaPagamentoRepository extends ServiceEntityRepository implements LancamentosDaPastaSobTrava
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaPagamento::class);
    }

    /**
     * Todos os pagamentos da pasta, do vencimento mais próximo para o mais
     * distante — a ordem em que o card do trilho lê, e a mesma em que os totais
     * são somados. Sem vencimento (o previsto de êxito e sucumbência) vai ao fim:
     * é o NULLS LAST do PostgreSQL no ASC. O desempate por id mantém a lista estável entre dois
     * lançamentos com o mesmo vencimento.
     *
     * @return PastaPagamento[]
     */
    public function findByPasta(Pasta $pasta, Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.pasta = :pasta')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('p.vencimento', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Os pagamentos da pasta com o autor já carregado (fetch join) — a timeline inteligente
     * escreve o nome de quem lançou em cada um, e o autor preguiçoso custaria uma consulta por
     * autor. Mesmo recorte de `findByPasta`: desta pasta E deste escritório.
     *
     * @return list<PastaPagamento>
     */
    public function findByPastaComAutor(Pasta $pasta, Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.autor', 'autor')
            ->addSelect('autor')
            ->andWhere('p.pasta = :pasta')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('p.vencimento', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trava a PASTA (`SELECT … FOR UPDATE`) e relê do BANCO os lançamentos dela, para congelar um
     * êxito ou sucumbência sobre a base de AGORA (spec pasta-exito-sucumbencia, decisão 3).
     *
     * Sem a trava, duas quitações ou correções simultâneas na mesma pasta leriam a mesma base e
     * congelariam sobre um número que a outra acabou de mudar. Sem o `HINT_REFRESH`, a consulta
     * devolveria as entidades do identity map com o estado de quando o request as carregou.
     * A ordem (pasta, depois as linhas) é a mesma em todo gesto que congela: sem deadlock.
     *
     * Exige transação aberta (quem chama abre e fecha).
     *
     * @return list<PastaPagamento> os lançamentos da pasta, inclusive o que vai congelar
     */
    public function travarPastaELerLancamentos(Pasta $pasta): array
    {
        $this->getEntityManager()->lock($pasta, LockMode::PESSIMISTIC_WRITE);

        /** @var list<PastaPagamento> */
        return $this->createQueryBuilder('p')
            ->andWhere('p.pasta = :pasta')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $pasta->getTenant())
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * Guarda de posse: o pagamento só existe se for DESTA pasta e DESTE
     * escritório. Quem chama devolve 404 quando vier nulo — 403 confirmaria
     * que o id existe em algum lugar.
     */
    public function findByIdAndPastaAndTenant(int $id, Pasta $pasta, Tenant $tenant): ?PastaPagamento
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('p.pasta = :pasta')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As correções de VALOR dos pagamentos informados, lidas do `audit_log` —
     * a fonte do "corrigido · era R$ a" do card (desenho 1.2.3, dc 3462).
     *
     * Só entra linha `update` desta classe, DESTE escritório (`tenant_id`), dos
     * ids pedidos, e que tenha mexido em `valor`: quitar e desfazer também são
     * `update` do pagamento e não são correção de valor. Nem quando a quitação
     * CONGELA o êxito ou a sucumbência (grava o valor calculado no mesmo update
     * que o `pagoEm`): isso é receber, não corrigir. Uma consulta para o
     * card inteiro, não uma por linha.
     *
     * Quem corrigiu sai pelo nome atual do usuário; sem usuário (removido),
     * pelo e-mail gravado na própria linha do audit_log.
     *
     * Nome de PROXY também conta: o `AuditLogSubscriber` grava
     * `$entity::class` sem normalizar, e um pagamento carregado como
     * referência preguiçosa do Doctrine sai como
     * `Proxies\__CG__\App\Pasta\Entity\PastaPagamento`. Normalizar no
     * subscriber mudaria o que se grava para TODAS as entidades auditadas (e
     * as linhas antigas continuariam com o nome de proxy); aceitar os dois
     * nomes aqui resolve o card sem mexer no histórico do sistema inteiro.
     *
     * @param PastaPagamento[] $pagamentos
     *
     * @return array<int, list<CorrecaoDeValorDoPagamentoOutput>> por id do pagamento, da mais antiga à mais recente
     */
    public function correcoesDeValor(array $pagamentos, Tenant $tenant): array
    {
        $ids = [];
        foreach ($pagamentos as $pagamento) {
            if ($pagamento->getId() !== null) {
                $ids[] = (string) $pagamento->getId();
            }
        }

        if ($ids === [] || $tenant->getId() === null) {
            return [];
        }

        $sql = <<<'SQL'
            SELECT a.entity_id,
                   a.created_at,
                   a.actor_email,
                   u.full_name,
                   a.changes->'diff'->'changes'->'valor'->>'from' AS de,
                   a.changes->'diff'->'changes'->'valor'->>'to'   AS para
            FROM audit_log a
            LEFT JOIN "user" u ON u.id = a.actor_user_id
            WHERE a.entity_class IN (:classe, :classeProxy)
              AND a.tenant_id    = :tenantId
              AND a.action       = 'update'
              AND a.entity_id IN (:ids)
              AND a.changes->'diff'->'changes'->'valor' IS NOT NULL
              AND a.changes->'diff'->'changes'->'pagoEm' IS NULL
            ORDER BY a.created_at ASC, a.id ASC
            SQL;

        $linhas = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            [
                'classe'      => PastaPagamento::class,
                'classeProxy' => self::nomeDoProxy($this->getEntityManager()->getConfiguration()->getProxyNamespace()),
                'tenantId'    => $tenant->getId(),
                'ids'         => $ids,
            ],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $porPagamento = [];
        foreach ($linhas as $linha) {
            if ($linha['de'] === null || $linha['para'] === null) {
                continue;
            }

            $nome  = trim((string) ($linha['full_name'] ?? ''));
            $autor = $nome !== '' ? $nome : trim((string) ($linha['actor_email'] ?? ''));

            $porPagamento[(int) $linha['entity_id']][] = new CorrecaoDeValorDoPagamentoOutput(
                em: new \DateTimeImmutable((string) $linha['created_at']),
                autor: $autor !== '' ? $autor : 'Usuário removido',
                de: (string) $linha['de'],
                para: (string) $linha['para'],
            );
        }

        return $porPagamento;
    }

    /**
     * O nome que o Doctrine dá ao proxy de `PastaPagamento` — a mesma montagem
     * do `ProxyFactory` (namespace dos proxies + `__CG__` + nome da classe).
     */
    public static function nomeDoProxy(?string $namespaceDosProxies): string
    {
        return rtrim($namespaceDosProxies ?? 'Proxies', '\\') . '\\' . Proxy::MARKER . '\\' . PastaPagamento::class;
    }

    /**
     * Quantos lançamentos cada pasta pedida tem e quantos estão vencidos (pendentes com vencimento
     * antes de `$hoje`, a regra de `PastaPagamento::estaVencido`), para o relatório de pastas
     * paradas. Um GROUP BY para o lote, com o tenant explícito.
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, array{total: int, vencidos: int}> pastaId => contagens (pasta sem lançamento não aparece)
     */
    public function resumoPorPasta(array $pastaIds, Tenant $tenant, \DateTimeImmutable $hoje): array
    {
        if ($pastaIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('p')
            ->select(
                'IDENTITY(p.pasta) AS pasta_id',
                // O previsto (êxito e sucumbência não pagos) fica fora do relatório: nem conta
                // como lançamento, nem vence (spec pasta-exito-sucumbencia, decisão 2).
                'SUM(CASE WHEN p.pagoEm IS NULL AND p.tipo IN (:condicionados) THEN 0 ELSE 1 END) AS total',
                'SUM(CASE WHEN p.pagoEm IS NULL AND p.tipo NOT IN (:condicionados) AND p.vencimento < :hoje THEN 1 ELSE 0 END) AS vencidos',
            )
            ->andWhere('p.tenant = :tenant')
            ->andWhere('IDENTITY(p.pasta) IN (:ids)')
            ->setParameter('condicionados', [TipoDePagamento::Exito->value, TipoDePagamento::Sucumbencia->value])
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $pastaIds)
            ->setParameter('hoje', $hoje->setTime(0, 0), 'date_immutable')
            ->groupBy('p.pasta')
            ->getQuery()
            ->getArrayResult();

        $porPasta = [];
        foreach ($linhas as $l) {
            if ((int) $l['total'] > 0) {
                $porPasta[(int) $l['pasta_id']] = ['total' => (int) $l['total'], 'vencidos' => (int) $l['vencidos']];
            }
        }

        return $porPasta;
    }
}
