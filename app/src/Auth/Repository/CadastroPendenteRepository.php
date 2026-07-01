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

    /**
     * Registros sem uso futuro que guardam senha_hash + PII e devem ser purgados:
     * os já confirmados (o User foi criado; o hash aqui é cópia morta) e os pendentes
     * cujo token expirou (cadastro abandonado). Pendentes ainda no prazo são cadastros
     * vivos e ficam de fora.
     */
    private function criterioPurgavel(\Doctrine\ORM\QueryBuilder $qb): \Doctrine\ORM\QueryBuilder
    {
        return $qb
            ->where('c.status = :confirmado OR (c.status = :pending AND c.expiresAt < :agora)')
            ->setParameter('confirmado', 'confirmado')
            ->setParameter('pending', 'pending');
    }

    /**
     * Conta quantos registros seriam purgados (usado no --dry-run).
     */
    public function contarPurgaveis(\DateTimeImmutable $agora): int
    {
        $qb = $this->criterioPurgavel($this->createQueryBuilder('c')->select('COUNT(c.id)'));

        return (int) $qb->setParameter('agora', $agora)->getQuery()->getSingleScalarResult();
    }

    /**
     * Apaga em massa os registros purgáveis. Retorna o nº de linhas removidas.
     */
    public function purgar(\DateTimeImmutable $agora): int
    {
        $qb = $this->criterioPurgavel($this->createQueryBuilder('c')->delete());

        return (int) $qb->setParameter('agora', $agora)->getQuery()->execute();
    }
}
