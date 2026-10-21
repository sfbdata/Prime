<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\TrabalhoExterno\Entity\LinkDeVagas;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LinkDeVagas> */
class LinkDeVagasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkDeVagas::class);
    }

    public function salvar(LinkDeVagas $item, bool $flush = false): void
    {
        $this->getEntityManager()->persist($item);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O link pelo token em claro: só o hash é consultado. */
    public function doToken(string $token): ?LinkDeVagas
    {
        return $token === '' ? null : $this->findOneBy(['tokenHash' => LinkDeVagas::hash($token)]);
    }
}
