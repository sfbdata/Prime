<?php

declare(strict_types=1);

namespace App\Tenant\Repository;

use App\Entity\Tenant\Tenant;
use App\Tenant\DTO\PedidoDeExclusaoPendenteOutput;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Enum\EstadoDoPedidoDeExclusao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Pedidos de exclusão de conta (PR 5). Tenant explícito em tudo: a barreira roda antes de o filtro de
 * escritório valer, e o painel do Master lê os pendentes de todas as contas.
 *
 * @extends ServiceEntityRepository<PedidoDeExclusaoDeConta>
 */
class PedidoDeExclusaoDeContaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PedidoDeExclusaoDeConta::class);
    }

    public function pendenteDoEscritorio(Tenant $tenant): ?PedidoDeExclusaoDeConta
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.estado = :pendente')
            ->setParameter('tenant', $tenant)
            ->setParameter('pendente', EstadoDoPedidoDeExclusao::Pendente)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Só existência, por id: a barreira pergunta a cada request (consulta curta, índice por tenant). */
    public function existePendente(int $tenantId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT 1 FROM pedido_exclusao_conta WHERE tenant_id = :t AND estado = 'pendente' LIMIT 1",
            ['t' => $tenantId],
        );
    }

    /**
     * Todos os pendentes, de todas as contas (painel do Master, frente b5). SQL direto: sem o filtro de
     * escritório da sessão do Master, que esconderia os pedidos das outras contas.
     *
     * @return list<PedidoDeExclusaoPendenteOutput>
     */
    public function pendentes(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT p.tenant_id, t.name AS escritorio, u.full_name, u.email, p.criado_em, p.motivo
               FROM pedido_exclusao_conta p
               JOIN tenant t ON t.id = p.tenant_id
          LEFT JOIN \"user\" u ON u.id = p.pedido_por_id
              WHERE p.estado = 'pendente'
           ORDER BY p.criado_em ASC",
        );

        return array_map(static fn (array $l): PedidoDeExclusaoPendenteOutput => new PedidoDeExclusaoPendenteOutput(
            tenantId: (int) $l['tenant_id'],
            escritorio: (string) $l['escritorio'],
            pedidoPorNome: $l['full_name'] !== null ? (string) $l['full_name'] : null,
            pedidoPorEmail: $l['email'] !== null ? (string) $l['email'] : null,
            criadoEm: new \DateTimeImmutable((string) $l['criado_em']),
            motivo: (string) $l['motivo'],
        ), $linhas);
    }

    public function salvar(PedidoDeExclusaoDeConta $pedido, bool $flush = false): void
    {
        $this->getEntityManager()->persist($pedido);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
