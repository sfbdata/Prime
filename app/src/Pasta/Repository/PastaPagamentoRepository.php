<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CorrecaoDeValorDoPagamentoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Proxy;

/**
 * @extends ServiceEntityRepository<PastaPagamento>
 */
final class PastaPagamentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaPagamento::class);
    }

    /**
     * Todos os pagamentos da pasta, do vencimento mais próximo para o mais
     * distante — a ordem em que o card do trilho lê, e a mesma em que os totais
     * são somados. O desempate por id mantém a lista estável entre dois
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
     * `update` do pagamento e não são correção de valor. Uma consulta para o
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
}
