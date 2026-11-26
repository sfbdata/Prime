<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\CicloDaConta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /** Desfaz a marcação quando o envio falhou: a boas-vindas pode sair na próxima vez. */
    public function desmarcarBoasVindas(int $userId, ?int $tenantId): void
    {
        $this->conexao()->executeStatement(
            'UPDATE comercial_ciclo_conta SET boas_vindas_em = NULL WHERE user_id = :u AND ' . self::escopo($tenantId),
            array_filter(['u' => $userId, 't' => $tenantId], static fn ($v): bool => $v !== null),
        );
    }

    public function desmarcarDica(int $id): void
    {
        $this->conexao()->executeStatement('UPDATE comercial_ciclo_conta SET dica_em = NULL WHERE id = :id', ['id' => $id]);
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

    /**
     * Quem está no dia de um passo da ativação (2 a 7) e ainda não recebeu nem esse passo nem um posterior. O "já saiu"
     * é o registro de envios: o Carteiro grava a linha na hora (até a bloqueada por descadastro), com a origem
     * `automacao:atN` e o endereço em minúsculas (como a MensagemDeEmail grava). Uma linha por pessoa, mesmo com a conta confirmada em mais de um escritório. Com
     * `semPasta`, só quem está num escritório que ainda não criou nenhuma pasta (o dia 6).
     *
     * @param list<string> $origensJaEnviadas as origens que barram (a do passo e as dos passos seguintes)
     *
     * @return list<int> os ids dos usuários
     */
    public function paraAtivacao(
        \DateTimeImmutable $corte,
        \DateTimeImmutable $confirmadoDesde,
        \DateTimeImmutable $confirmadoAte,
        array $origensJaEnviadas,
        bool $semPasta,
        int $limite,
    ): array {
        $filtroDaPasta = $semPasta
            ? ' AND c.tenant_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pasta p WHERE p.tenant_id = c.tenant_id)'
            : '';
        $linhas = $this->conexao()->fetchFirstColumn(
            'SELECT DISTINCT c.user_id FROM comercial_ciclo_conta c
               JOIN "user" u ON u.id = c.user_id
              WHERE c.confirmado_em >= :corte AND c.confirmado_em > :desde AND c.confirmado_em <= :ate
                AND NOT EXISTS (SELECT 1 FROM comercial_envio e WHERE e.destinatario = LOWER(TRIM(u.email)) AND e.origem IN (:origens))'
                . $filtroDaPasta . '
              ORDER BY c.user_id LIMIT ' . max(1, $limite),
            [
                'corte' => $corte->format('Y-m-d H:i:s'),
                'desde' => $confirmadoDesde->format('Y-m-d H:i:s'),
                'ate' => $confirmadoAte->format('Y-m-d H:i:s'),
                'origens' => $origensJaEnviadas,
            ],
            ['origens' => ArrayParameterType::STRING],
        );

        return array_map(static fn (mixed $id): int => (int) $id, $linhas);
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
