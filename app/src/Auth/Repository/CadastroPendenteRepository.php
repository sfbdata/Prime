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
        // Ignora a caixa: pendentes gravados antes da normalização (`Ana@Adv.com`) não seriam
        // encontrados por uma busca exata com o e-mail já minúsculo, e sobreviveriam ao
        // reinício do cadastro — continuando confirmáveis.
        return $this->createQueryBuilder('c')
            ->where('LOWER(c.email) = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->getQuery()
            ->getResult();
    }

    /** Folga entre vencer e ser purgado: o lembrete "E-mail não confirmado" de 72 h ainda acha o pendente. */
    public const FOLGA_DA_PURGA = '-72 hours';

    /**
     * Registros sem uso futuro que guardam senha_hash + PII e devem ser purgados:
     * os já confirmados (o User foi criado; o hash aqui é cópia morta) e os pendentes
     * vencidos há mais de 72 h (cadastro abandonado; a folga é do lembrete com link novo,
     * docs/specs/comercial-e-emails.md §3.5). Pendentes ainda no prazo, ou vencidos há
     * menos de 72 h, ficam de fora.
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

        return (int) $qb->setParameter('agora', $agora->modify(self::FOLGA_DA_PURGA))->getQuery()->getSingleScalarResult();
    }

    /**
     * Apaga em massa os registros purgáveis. Retorna o nº de linhas removidas.
     */
    public function purgar(\DateTimeImmutable $agora): int
    {
        $qb = $this->criterioPurgavel($this->createQueryBuilder('c')->delete());

        return (int) $qb->setParameter('agora', $agora->modify(self::FOLGA_DA_PURGA))->getQuery()->execute();
    }

    /**
     * Pendentes que o lembrete "E-mail não confirmado" alcança: ainda pendentes, sem lembrete, criados entre o corte
     * da automação (`ligada_em`) e `agora - atraso`.
     *
     * @return list<CadastroPendente>
     */
    public function semConfirmacaoParaLembrete(\DateTimeImmutable $corte, \DateTimeImmutable $criadoAte, int $limite): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :pending')
            ->andWhere('c.lembreteEm IS NULL')
            ->andWhere('c.createdAt >= :corte')
            ->andWhere('c.createdAt <= :criadoAte')
            ->setParameter('pending', 'pending')
            ->setParameter('corte', $corte)
            ->setParameter('criadoAte', $criadoAte)
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
