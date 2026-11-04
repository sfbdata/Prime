<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\AcessoDiario;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os acessos ao sistema, uma linha por pessoa por dia ({@see AcessoDiario}). Global (sem tenant): quem lê é
 * só o Master, e só a contagem por dia (nenhum nome sai daqui).
 *
 * @extends ServiceEntityRepository<AcessoDiario>
 */
class AcessoDiarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcessoDiario::class);
    }

    /**
     * Marca que a pessoa entrou no dia. A segunda entrada do mesmo dia não faz nada (ON CONFLICT DO NOTHING):
     * uma linha por pessoa por dia, sem corrida entre dois logins simultâneos. Fora do EntityManager de
     * propósito: não toca na unidade de trabalho do login.
     */
    public function registrar(int $usuarioId, \DateTimeImmutable $dia): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO master_acesso_diario (usuario_id, dia) VALUES (:usuario, :dia) ON CONFLICT (usuario_id, dia) DO NOTHING',
            ['usuario' => $usuarioId, 'dia' => $dia->format('Y-m-d')],
        );
    }

    /**
     * Quantas pessoas entraram em cada dia.
     *
     * @return array<string, int> 'YYYY-MM-DD' => pessoas
     */
    public function pessoasPorDia(): array
    {
        $saida = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT to_char(dia, 'YYYY-MM-DD') AS dia, COUNT(*) AS n FROM master_acesso_diario GROUP BY dia ORDER BY dia",
        ) as $l) {
            $saida[(string) $l['dia']] = (int) $l['n'];
        }

        return $saida;
    }
}
