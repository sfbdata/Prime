<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Enum\SituacaoConvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Convites são lidos por dois caminhos só: pelo token (o link do e-mail) e pelo usuário que aceitou.
 * O resto passa pela demanda, já escopada.
 *
 * @extends ServiceEntityRepository<ConviteDemanda>
 */
class ConviteDemandaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConviteDemanda::class);
    }

    public function peloToken(string $token): ?ConviteDemanda
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return $this->findOneBy(['tokenHash' => ConviteDemanda::hashDoToken($token)]);
    }

    /**
     * Os convites aceitos e vigentes deste usuário: é o que dá a ele o perfil Convidado.
     *
     * @return list<ConviteDemanda>
     */
    public function vigentesDo(User $usuario, \DateTimeImmutable $hoje): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.convidado = :usuario')
            ->andWhere('c.situacao = :aceito')
            ->andWhere('c.validade >= :hoje')
            ->setParameter('usuario', $usuario)
            ->setParameter('aceito', SituacaoConvite::Aceito)
            ->setParameter('hoje', $hoje->setTime(0, 0), 'date_immutable')
            ->orderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
