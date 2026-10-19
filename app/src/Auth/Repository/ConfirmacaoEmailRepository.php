<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\Entity\ConfirmacaoEmail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConfirmacaoEmail>
 */
class ConfirmacaoEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfirmacaoEmail::class);
    }

    public function encontrarPorToken(string $token): ?ConfirmacaoEmail
    {
        return $this->findOneBy(['tokenHash' => ConfirmacaoEmail::hashDoToken($token)]);
    }

    public function salvar(ConfirmacaoEmail $confirmacao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($confirmacao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
