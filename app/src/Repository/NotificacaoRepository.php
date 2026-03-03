<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notificacao>
 */
class NotificacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notificacao::class);
    }

    /**
     * Retorna notificações não lidas do usuário
     * 
     * @return Notificacao[]
     */
    public function findNaoLidasByUsuario(User $usuario, int $limit = 10): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario)
            ->orderBy('n.criadaEm', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Conta notificações não lidas do usuário
     */
    public function countNaoLidasByUsuario(User $usuario): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retorna todas as notificações do usuário (lidas e não lidas)
     * 
     * @return Notificacao[]
     */
    public function findByUsuario(User $usuario, int $limit = 50): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.usuario = :usuario')
            ->setParameter('usuario', $usuario)
            ->orderBy('n.criadaEm', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Marca todas as notificações do usuário como lidas
     */
    public function marcarTodasComoLidas(User $usuario): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.lida', 'true')
            ->set('n.lidaEm', ':agora')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario)
            ->setParameter('agora', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    /**
     * Remove notificações antigas (mais de 30 dias)
     */
    public function removerAntigas(int $dias = 30): int
    {
        $limite = new \DateTimeImmutable("-{$dias} days");

        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.criadaEm < :limite')
            ->setParameter('limite', $limite)
            ->getQuery()
            ->execute();
    }
}
