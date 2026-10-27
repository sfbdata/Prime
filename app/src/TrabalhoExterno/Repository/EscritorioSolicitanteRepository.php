<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Entity\EscritorioSolicitante;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Escritórios guardados por UM usuário: toda consulta recebe o usuário (não há leitura por id solto).
 *
 * @extends ServiceEntityRepository<EscritorioSolicitante>
 */
class EscritorioSolicitanteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EscritorioSolicitante::class);
    }

    public function salvar(EscritorioSolicitante $escritorio, bool $flush = false): void
    {
        $this->getEntityManager()->persist($escritorio);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Só devolve se for do usuário; de outro, é como se não existisse. */
    public function doUsuarioPorId(User $usuario, int $id): ?EscritorioSolicitante
    {
        return $this->findOneBy(['id' => $id, 'usuario' => $usuario]);
    }

    /** @return list<EscritorioSolicitante> */
    public function doUsuario(User $usuario): array
    {
        return $this->findBy(['usuario' => $usuario], ['nome' => 'ASC', 'id' => 'ASC']);
    }

    public function contarDoUsuario(User $usuario): int
    {
        return $this->count(['usuario' => $usuario]);
    }
}
