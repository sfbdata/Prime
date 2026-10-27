<?php

declare(strict_types=1);

namespace App\Recompensa\Repository;

use App\Recompensa\Entity\AprovacaoDeContaDeRecompensa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Aprovações de contas suspeitas. Sem filtro de tenant de propósito: é dado GLOBAL da plataforma, lido e escrito só pelo
 * Master (ver {@see AprovacaoDeContaDeRecompensa}).
 *
 * @extends ServiceEntityRepository<AprovacaoDeContaDeRecompensa>
 */
class AprovacaoDeContaDeRecompensaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AprovacaoDeContaDeRecompensa::class);
    }

    public function salvar(AprovacaoDeContaDeRecompensa $aprovacao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($aprovacao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** @return array<int, true> ids das contas já aprovadas para o indicador */
    public function contasAprovadasDoIndicador(int $indicadorId): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT conta_id FROM aprovacao_de_conta_de_recompensa WHERE indicador_id = ?',
            [$indicadorId],
        );

        return array_fill_keys(array_map('intval', $ids), true);
    }
}
