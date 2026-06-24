<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
     * Aplica o filtro de categoria (derivada do tipo) ao QueryBuilder.
     * null = todas as categorias.
     */
    private function aplicarCategoria(QueryBuilder $qb, ?string $categoria): void
    {
        if ($categoria === Notificacao::CATEGORIA_GESTAO) {
            $qb->andWhere('n.tipo IN (:tiposGestao)')
                ->setParameter('tiposGestao', Notificacao::TIPOS_GESTAO);
        } elseif ($categoria === Notificacao::CATEGORIA_PESSOAL) {
            $qb->andWhere('n.tipo NOT IN (:tiposGestao)')
                ->setParameter('tiposGestao', Notificacao::TIPOS_GESTAO);
        }
    }

    /**
     * Retorna notificações não lidas do usuário (opcionalmente de uma categoria)
     *
     * @return Notificacao[]
     */
    public function findNaoLidasByUsuario(User $usuario, ?string $categoria = null, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario)
            ->orderBy('n.criadaEm', 'DESC')
            ->setMaxResults($limit);
        $this->aplicarCategoria($qb, $categoria);

        return $qb->getQuery()->getResult();
    }

    /**
     * Conta notificações não lidas do usuário (opcionalmente de uma categoria)
     */
    public function countNaoLidasByUsuario(User $usuario, ?string $categoria = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario);
        $this->aplicarCategoria($qb, $categoria);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Retorna uma página de notificações do usuário (opcionalmente de uma categoria),
     * ordenadas da mais recente para a mais antiga.
     *
     * @return Notificacao[]
     */
    public function findPaginadasByUsuario(User $usuario, ?string $categoria, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.usuario = :usuario')
            ->setParameter('usuario', $usuario)
            ->orderBy('n.criadaEm', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $this->aplicarCategoria($qb, $categoria);

        return $qb->getQuery()->getResult();
    }

    /**
     * Conta o total de notificações do usuário (opcionalmente de uma categoria),
     * para o cálculo de páginas.
     */
    public function countByUsuario(User $usuario, ?string $categoria = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->setParameter('usuario', $usuario);
        $this->aplicarCategoria($qb, $categoria);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Indica se o usuário possui alguma notificação de gestão (para liberar a aba)
     */
    public function temNotificacaoGestao(User $usuario): bool
    {
        $count = (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->andWhere('n.tipo IN (:tiposGestao)')
            ->setParameter('usuario', $usuario)
            ->setParameter('tiposGestao', Notificacao::TIPOS_GESTAO)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Marca todas as notificações do usuário como lidas (opcionalmente de uma categoria)
     */
    public function marcarTodasComoLidas(User $usuario, ?string $categoria = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->update()
            ->set('n.lida', 'true')
            ->set('n.lidaEm', ':agora')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario)
            ->setParameter('agora', new \DateTimeImmutable());
        $this->aplicarCategoria($qb, $categoria);

        return $qb->getQuery()->execute();
    }

    /**
     * Exclui as notificações informadas que pertençam ao usuário.
     * O filtro por usuário garante que IDs de outro usuário sejam ignorados.
     *
     * @param int[] $ids
     * @return int quantidade efetivamente excluída
     */
    public function excluirDoUsuario(User $usuario, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.usuario = :usuario')
            ->andWhere('n.id IN (:ids)')
            ->setParameter('usuario', $usuario)
            ->setParameter('ids', $ids)
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
