<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\TrabalhoExterno\Entity\InteressadoEmVagas;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InteressadoEmVagas> */
class InteressadoEmVagasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InteressadoEmVagas::class);
    }

    public function salvar(InteressadoEmVagas $item, bool $flush = false): void
    {
        $this->getEntityManager()->persist($item);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function doEmail(string $email): ?InteressadoEmVagas
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function contar(): int
    {
        return $this->count([]);
    }

    /**
     * Todos os inscritos nos alertas, do mais antigo ao mais novo: o CSV do KPI "ALERTAS POR E-MAIL"
     * (spec §4.9), só para o Master.
     *
     * @return list<InteressadoEmVagas>
     */
    public function paraExportar(): array
    {
        return $this->createQueryBuilder('i')->orderBy('i.criadoEm', 'ASC')->addOrderBy('i.id', 'ASC')->getQuery()->getResult();
    }
}
