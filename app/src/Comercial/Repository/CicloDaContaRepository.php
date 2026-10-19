<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\CicloDaConta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O ciclo da conta (spec §3.4), por SQL, com marcações atômicas: a boas-vindas e a dica saem no máximo uma vez por
 * pessoa e escritório (a segunda marcação não acha linha a mudar). `tenant_id` null = conta de plataforma.
 *
 * @extends ServiceEntityRepository<CicloDaConta>
 */
class CicloDaContaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CicloDaConta::class);
    }

    /** Garante a linha da pessoa no escritório; com `confirmadoEm`, grava a confirmação se ainda não houver. */
    public function garantir(int $userId, ?int $tenantId, ?\DateTimeImmutable $confirmadoEm): void
    {
        $conflito = $tenantId === null ? '(user_id) WHERE tenant_id IS NULL' : '(user_id, tenant_id) WHERE tenant_id IS NOT NULL';
        $this->conexao()->executeStatement(
            'INSERT INTO comercial_ciclo_conta (user_id, tenant_id, confirmado_em) VALUES (:u, :t, :c)
             ON CONFLICT ' . $conflito . ' DO UPDATE SET confirmado_em = COALESCE(comercial_ciclo_conta.confirmado_em, EXCLUDED.confirmado_em)',
            ['u' => $userId, 't' => $tenantId, 'c' => $confirmadoEm?->format('Y-m-d H:i:s')],
        );
    }

    /** true se esta chamada marcou (então a boas-vindas sai); false se já tinha saído. */
    public function marcarBoasVindas(int $userId, ?int $tenantId, \DateTimeImmutable $agora): bool
    {
        return $this->conexao()->executeStatement(
            'UPDATE comercial_ciclo_conta SET boas_vindas_em = :agora
              WHERE user_id = :u AND ' . self::escopo($tenantId) . ' AND boas_vindas_em IS NULL',
            array_filter(['agora' => $agora->format('Y-m-d H:i:s'), 'u' => $userId, 't' => $tenantId], static fn ($v): bool => $v !== null),
        ) === 1;
    }

    /**
     * Contas confirmadas entre o corte e `confirmadoAte`, ainda sem a dica.
     *
     * @return list<array{id: int, user_id: int, tenant_id: int|null}>
     */
    public function paraDica(\DateTimeImmutable $corte, \DateTimeImmutable $confirmadoAte, int $limite): array
    {
        $linhas = $this->conexao()->fetchAllAssociative(
            'SELECT id, user_id, tenant_id FROM comercial_ciclo_conta
              WHERE dica_em IS NULL AND confirmado_em >= :corte AND confirmado_em <= :ate
              ORDER BY id LIMIT ' . max(1, $limite),
            ['corte' => $corte->format('Y-m-d H:i:s'), 'ate' => $confirmadoAte->format('Y-m-d H:i:s')],
        );

        return array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'user_id' => (int) $l['user_id'], 'tenant_id' => $l['tenant_id'] !== null ? (int) $l['tenant_id'] : null], $linhas);
    }

    public function marcarDica(int $id, \DateTimeImmutable $agora): bool
    {
        return $this->conexao()->executeStatement(
            'UPDATE comercial_ciclo_conta SET dica_em = :agora WHERE id = :id AND dica_em IS NULL',
            ['agora' => $agora->format('Y-m-d H:i:s'), 'id' => $id],
        ) === 1;
    }

    private static function escopo(?int $tenantId): string
    {
        return $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :t';
    }

    private function conexao(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
