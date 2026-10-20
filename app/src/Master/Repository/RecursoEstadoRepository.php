<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\RecursoEstado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Estados das telas e funções da plataforma. Sem filtro de tenant de propósito: `recurso_estado` é
 * configuração GLOBAL (ver {@see RecursoEstado}), no máximo uma linha por chave do catálogo (18).
 *
 * @extends ServiceEntityRepository<RecursoEstado>
 */
class RecursoEstadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecursoEstado::class);
    }

    public function buscarPorChave(string $chave): ?RecursoEstado
    {
        return $this->findOneBy(['chave' => $chave]);
    }

    /**
     * Todas as linhas gravadas, indexadas pela chave. Lista curta e limitada pelo catálogo.
     *
     * @return array<string, RecursoEstado>
     */
    public function todosPorChave(): array
    {
        $porChave = [];
        foreach ($this->createQueryBuilder('r')->getQuery()->getResult() as $estado) {
            $porChave[$estado->getChave()] = $estado;
        }

        return $porChave;
    }

    public function salvar(RecursoEstado $estado, bool $flush = false): void
    {
        $this->getEntityManager()->persist($estado);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
