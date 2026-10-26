<?php

declare(strict_types=1);

namespace App\Recompensa\Repository;

use App\Recompensa\Entity\CondicaoDeRecompensa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Condições individuais de recompensa, uma por indicador. Sem filtro de tenant de propósito: é dado GLOBAL da plataforma,
 * lido só pelo Master (ver {@see CondicaoDeRecompensa}).
 *
 * @extends ServiceEntityRepository<CondicaoDeRecompensa>
 */
class CondicaoDeRecompensaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CondicaoDeRecompensa::class);
    }

    /** @return array<int, CondicaoDeRecompensa> indexadas pelo id do indicador (uma consulta para a lista toda) */
    public function todasPorIndicador(): array
    {
        $porIndicador = [];
        foreach ($this->findAll() as $condicao) {
            $porIndicador[$condicao->getUserId()] = $condicao;
        }

        return $porIndicador;
    }

    public function buscarDoIndicador(int $userId): ?CondicaoDeRecompensa
    {
        return $this->findOneBy(['user' => $userId]);
    }

    public function salvar(CondicaoDeRecompensa $condicao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($condicao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(CondicaoDeRecompensa $condicao, bool $flush = false): void
    {
        $this->getEntityManager()->remove($condicao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
