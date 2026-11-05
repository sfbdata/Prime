<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Auth\Invitation;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    /** O link traz o token puro; o banco só tem o hash dele. */
    public function encontrarPorToken(string $token): ?Invitation
    {
        return $this->findOneBy(['tokenHash' => Invitation::hashDoToken($token)]);
    }

    /** @return Invitation[] */
    public function encontrarPendentesPorEmail(string $email): array
    {
        // I3: não listar convite de escritório soft-deletado (tenant inativo).
        // Convites de plataforma (tenant nulo) continuam aparecendo.
        return $this->createQueryBuilder('i')
            ->leftJoin('i.tenant', 't')
            ->andWhere('i.email = :email')
            ->andWhere('i.status = :status')
            ->andWhere('i.expiresAt > :now')
            ->andWhere('i.tenant IS NULL OR t.isActive = true')
            ->setParameter('email', $email)
            ->setParameter('status', 'pending')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Convites de escritório pendentes e não expirados para o e-mail, sem diferença de maiúsculas (o aceite pelo id
     * compara assim). Inclui o de escritório fora do ar: quem consulta é a regra C8 da senha, que erra para o lado de
     * recusar (auditoria 1.04).
     *
     * @return Invitation[]
     */
    public function encontrarDeEscritorioPendentesPorEmail(string $email): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('LOWER(i.email) = :email')
            ->andWhere('i.type = :tipo')
            ->andWhere('i.status = :status')
            ->andWhere('i.expiresAt > :now')
            ->andWhere('i.tenant IS NOT NULL')
            ->setParameter('email', mb_strtolower($email))
            ->setParameter('tipo', Invitation::TIPO_ESCRITORIO)
            ->setParameter('status', 'pending')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /** @return Invitation[] */
    public function listarDePlataforma(): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.type = :type')
            ->setParameter('type', Invitation::TIPO_PLATAFORMA)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return Invitation[] */
    public function listarPorTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
