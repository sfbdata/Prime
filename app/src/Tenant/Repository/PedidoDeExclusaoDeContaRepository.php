<?php

declare(strict_types=1);

namespace App\Tenant\Repository;

use App\Entity\Tenant\Tenant;
use App\Tenant\DTO\PedidoDeExclusaoPendenteOutput;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Enum\EstadoDoPedidoDeExclusao;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
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

    /**
     * O pedido pendente desta conta. Consulta NATIVA de propósito: o filtro de escritório (TenantFilter)
     * não vale para ela. O Master decide pelo painel com o escritório DELE na sessão, e uma DQL aqui
     * esconderia o pedido do cliente (Reativar recusaria e Excluir deixaria o pedido pendente). O escritório
     * é sempre o do parâmetro.
     */
    public function pendenteDoEscritorio(Tenant $tenant): ?PedidoDeExclusaoDeConta
    {
        $mapa = new ResultSetMappingBuilder($this->getEntityManager());
        $mapa->addRootEntityFromClassMetadata(PedidoDeExclusaoDeConta::class, 'p');

        return $this->getEntityManager()
            ->createNativeQuery(
                "SELECT {$mapa->generateSelectClause()} FROM pedido_exclusao_conta p WHERE p.tenant_id = :tenant AND p.estado = :pendente LIMIT 1",
                $mapa,
            )
            ->setParameter('tenant', $tenant->getId())
            ->setParameter('pendente', EstadoDoPedidoDeExclusao::Pendente->value)
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

    /**
     * Grava a decisão já aplicada no pedido (desistir, marcarReativada ou marcarExcluida) SÓ se ele ainda estiver
     * pendente no banco, na mesma transação de `$gravar` (o flush da conta). A desistência do primário e a
     * decisão do Master ao mesmo tempo: o UPDATE condicional serializa as duas (o segundo espera o lock da
     * linha e, depois do COMMIT do primeiro, não acha mais `pendente`), e quem chega depois recebe 409.
     *
     * @throws PedidoDeExclusaoRecusado 409, quando o pedido já foi decidido
     */
    public function gravarDecisaoSePendente(PedidoDeExclusaoDeConta $pedido, callable $gravar): void
    {
        $conexao = $this->getEntityManager()->getConnection();
        $conexao->beginTransaction();

        try {
            $linhas = $conexao->executeStatement(
                'UPDATE pedido_exclusao_conta SET estado = :estado, decidido_por_id = :ator, decidido_em = :em WHERE id = :id AND estado = :pendente',
                [
                    'estado'   => $pedido->getEstado()->value,
                    'ator'     => $pedido->getDecididoPor()?->getId(),
                    'em'       => $pedido->getDecididoEm()?->format('Y-m-d H:i:s'),
                    'id'       => $pedido->getId(),
                    'pendente' => EstadoDoPedidoDeExclusao::Pendente->value,
                ],
            );
            if ($linhas !== 1) {
                throw new PedidoDeExclusaoRecusado('Este pedido de exclusão já foi decidido. Recarregue a página.', 409);
            }

            $gravar();
            $conexao->commit();
        } catch (\Throwable $e) {
            $conexao->rollBack();

            throw $e;
        }
    }

    public function salvar(PedidoDeExclusaoDeConta $pedido, bool $flush = false): void
    {
        $this->getEntityManager()->persist($pedido);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
