<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\Entity\MensagemSuporte;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MensagemSuporte>
 */
class MensagemSuporteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MensagemSuporte::class);
    }

    /** @return list<MensagemSuporte> as mais novas primeiro */
    public function recentes(int $limite = 200): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.criadaEm', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    public function salvar(MensagemSuporte $mensagem, bool $flush = false): void
    {
        $this->getEntityManager()->persist($mensagem);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
