<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\Entity\AjusteEntrada;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AjusteEntrada>
 */
class AjusteEntradaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AjusteEntrada::class);
    }

    /** @return array<string, AjusteEntrada> por chave */
    public function todosPorChave(): array
    {
        $porChave = [];
        foreach ($this->findAll() as $ajuste) {
            $porChave[$ajuste->getChave()] = $ajuste;
        }

        return $porChave;
    }

    /** O ajuste gravado da chave (`link.termos`, `suporte.destinatario`...) ou nulo quando vale o padrão. */
    public function daChave(string $chave): ?AjusteEntrada
    {
        return $this->find($chave);
    }

    public function salvar(AjusteEntrada $ajuste, bool $flush = false): void
    {
        $this->getEntityManager()->persist($ajuste);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(AjusteEntrada $ajuste, bool $flush = false): void
    {
        $this->getEntityManager()->remove($ajuste);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
