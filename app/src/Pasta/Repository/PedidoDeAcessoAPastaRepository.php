<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\EstadoDoPedidoDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PedidoDeAcessoAPasta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Pedidos de acesso a pastas com cadeado. Toda consulta é escopada ao escritório da pasta ou ao passado.
 *
 * @extends ServiceEntityRepository<PedidoDeAcessoAPasta>
 */
class PedidoDeAcessoAPastaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PedidoDeAcessoAPasta::class);
    }

    public function salvar(PedidoDeAcessoAPasta $pedido, bool $flush = false): void
    {
        $this->getEntityManager()->persist($pedido);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O pedido mais recente da pessoa nesta pasta, de qualquer estado (a tela trancada mostra o estado dele). */
    public function ultimoDe(Pasta $pasta, User $solicitante): ?PedidoDeAcessoAPasta
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.pasta = :pasta')
            ->andWhere('p.solicitante = :solicitante')
            ->setParameter('tenant', $pasta->getTenant())
            ->setParameter('pasta', $pasta)
            ->setParameter('solicitante', $solicitante)
            ->orderBy('p.pedidoEm', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function pendenteDe(Pasta $pasta, User $solicitante): ?PedidoDeAcessoAPasta
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.pasta = :pasta')
            ->andWhere('p.solicitante = :solicitante')
            ->andWhere('p.estado = :pendente')
            ->setParameter('tenant', $pasta->getTenant())
            ->setParameter('pasta', $pasta)
            ->setParameter('solicitante', $solicitante)
            ->setParameter('pendente', EstadoDoPedidoDeAcesso::Pendente)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Os pendentes da pasta, do mais antigo ao mais novo: é a faixa de decisão que quem configura vê.
     *
     * @return list<PedidoDeAcessoAPasta>
     */
    public function pendentesDaPasta(Pasta $pasta): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('s')
            ->join('p.solicitante', 's')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.pasta = :pasta')
            ->andWhere('p.estado = :pendente')
            ->setParameter('tenant', $pasta->getTenant())
            ->setParameter('pasta', $pasta)
            ->setParameter('pendente', EstadoDoPedidoDeAcesso::Pendente)
            ->orderBy('p.pedidoEm', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** O pedido pelo id, só se for deste escritório E desta pasta (guarda IDOR da decisão). */
    public function daPasta(int $pedidoId, Pasta $pasta, Tenant $tenant): ?PedidoDeAcessoAPasta
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.pasta = :pasta')
            ->setParameter('id', $pedidoId)
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * A trava da decisão (concorrência): grava a decisão só se o pedido AINDA está pendente, num UPDATE
     * condicional. Duas decisões ao mesmo tempo: só a primeira muda a linha; a segunda recebe `false`.
     */
    public function reservarDecisao(PedidoDeAcessoAPasta $pedido, EstadoDoPedidoDeAcesso $estado, User $por, \DateTimeImmutable $em): bool
    {
        $alteradas = $this->getEntityManager()->createQueryBuilder()
            ->update(PedidoDeAcessoAPasta::class, 'p')
            ->set('p.estado', ':estado')
            ->set('p.decididoPor', ':por')
            ->set('p.decididoEm', ':em')
            ->andWhere('p.id = :id')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.estado = :pendente')
            ->setParameter('estado', $estado)
            ->setParameter('por', $por)
            ->setParameter('em', $em)
            ->setParameter('id', $pedido->getId())
            ->setParameter('tenant', $pedido->getTenant())
            ->setParameter('pendente', EstadoDoPedidoDeAcesso::Pendente)
            ->getQuery()
            ->execute();

        return $alteradas === 1;
    }
}
