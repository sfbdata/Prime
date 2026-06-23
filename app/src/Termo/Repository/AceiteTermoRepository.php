<?php

declare(strict_types=1);

namespace App\Termo\Repository;

use App\Entity\Auth\User;
use App\Termo\Entity\AceiteTermo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AceiteTermo>
 */
class AceiteTermoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AceiteTermo::class);
    }

    public function salvar(AceiteTermo $aceite, bool $flush = false): void
    {
        $this->getEntityManager()->persist($aceite);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Verifica se o usuário já aceitou a versão informada.
     *
     * Exceção consciente à regra de filtro por tenant: o aceite é da plataforma,
     * não de um escritório — a busca é por (user, versao), apoiada no índice único.
     */
    public function existeAceiteVigente(User $user, string $versao): bool
    {
        $resultado = $this->createQueryBuilder('a')
            ->select('1')
            ->where('a.user = :user')
            ->andWhere('a.versao = :versao')
            ->setParameter('user', $user)
            ->setParameter('versao', $versao)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $resultado !== null;
    }
}
