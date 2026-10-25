<?php

declare(strict_types=1);

namespace App\Inteligencia\Repository;

use App\Inteligencia\Entity\MudancaDeStatusDaQuestaoJuridica;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O histórico de status das questões jurídicas. PLATAFORMA, sem tenant.
 *
 * @extends ServiceEntityRepository<MudancaDeStatusDaQuestaoJuridica>
 */
class MudancaDeStatusDaQuestaoJuridicaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MudancaDeStatusDaQuestaoJuridica::class);
    }

    /** @return array<string, list<MudancaDeStatusDaQuestaoJuridica>> pela chave da questão, a mais recente primeiro */
    public function todasPorChave(): array
    {
        $mudancas = $this->createQueryBuilder('m')
            ->orderBy('m.mudadaEm', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();

        $porChave = [];
        foreach ($mudancas as $mudanca) {
            $porChave[$mudanca->getChave()][] = $mudanca;
        }

        return $porChave;
    }
}
