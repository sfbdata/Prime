<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\Convenio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os convênios do BlueJus (`convenio`, spec docs/specs/convenios.md, §3.1). Sem filtro de tenant de propósito: é
 * configuração GLOBAL da plataforma (ver {@see Convenio}).
 *
 * @extends ServiceEntityRepository<Convenio>
 */
class ConvenioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Convenio::class);
    }

    public function porChave(string $chave): ?Convenio
    {
        return $this->findOneBy(['chave' => $chave]);
    }

    public function salvar(Convenio $convenio, bool $flush = false): void
    {
        $this->getEntityManager()->persist($convenio);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
