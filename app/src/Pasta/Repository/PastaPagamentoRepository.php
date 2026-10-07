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
            WHERE a.entity_class = :classe
              AND a.tenant_id    = :tenantId
              AND a.action       = 'update'
              AND a.entity_id IN (:ids)
              AND a.changes->'diff'->'changes'->'valor' IS NOT NULL
            ORDER BY a.created_at ASC, a.id ASC
            SQL;

        $linhas = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            ['classe' => PastaPagamento::class, 'tenantId' => $tenant->getId(), 'ids' => $ids],
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
}
