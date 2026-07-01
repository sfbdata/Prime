<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\Entity\CadastroPendente;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CadastroPendente>
 */
class CadastroPendenteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CadastroPendente::class);
    }

    public function encontrarPorToken(string $token): ?CadastroPendente
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Todos os cadastros pendentes de um e-mail (usado para limpar tentativas
     * anteriores quando o mesmo e-mail reinicia o cadastro).
     *
     * @return CadastroPendente[]
     */
    public function encontrarPorEmail(string $email): array
    {
        return $this->findBy(['email' => $email]);
    }
}
