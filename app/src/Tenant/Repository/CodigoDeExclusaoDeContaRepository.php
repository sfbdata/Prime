<?php

declare(strict_types=1);

namespace App\Tenant\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\Entity\CodigoDeExclusaoDeConta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Códigos da etapa 2 da exclusão da conta (PR 5). As operações que contam (tentativa e consumo) são
 * UPDATE atômicos com a condição no WHERE: dois envios simultâneos não passam do limite nem usam o mesmo
 * código duas vezes.
 *
 * @extends ServiceEntityRepository<CodigoDeExclusaoDeConta>
 */
class CodigoDeExclusaoDeContaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodigoDeExclusaoDeConta::class);
    }

    /** Emitir um código novo apaga os anteriores do par (vivos ou vencidos): só o último vale. */
    public function apagarDoPar(Tenant $tenant, User $usuario): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM codigo_exclusao_conta WHERE tenant_id = :t AND usuario_id = :u',
            ['t' => $tenant->getId(), 'u' => $usuario->getId()],
        );
    }

    /** O código vivo do par: não usado, não vencido, com tentativas sobrando. */
    public function vivoDoPar(Tenant $tenant, User $usuario, \DateTimeImmutable $agora): ?CodigoDeExclusaoDeConta
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :t')
            ->andWhere('c.usuario = :u')
            ->andWhere('c.usadoEm IS NULL')
            ->andWhere('c.expiraEm > :agora')
            ->andWhere('c.tentativas < :max')
            ->setParameter('t', $tenant)
            ->setParameter('u', $usuario)
            ->setParameter('agora', $agora)
            ->setParameter('max', CodigoDeExclusaoDeConta::MAX_TENTATIVAS)
            ->orderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Reserva uma tentativa ANTES de comparar. False = o limite já foi atingido (o código está queimado). */
    public function reservarTentativa(CodigoDeExclusaoDeConta $codigo): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE codigo_exclusao_conta SET tentativas = tentativas + 1 WHERE id = :id AND usado_em IS NULL AND tentativas < :max',
            ['id' => $codigo->getId(), 'max' => CodigoDeExclusaoDeConta::MAX_TENTATIVAS],
        ) === 1;
    }

    /** Consome o código. False = outro pedido já o consumiu. */
    public function consumir(CodigoDeExclusaoDeConta $codigo, \DateTimeImmutable $agora): bool
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE codigo_exclusao_conta SET usado_em = :agora WHERE id = :id AND usado_em IS NULL',
            ['id' => $codigo->getId(), 'agora' => $agora->format('Y-m-d H:i:s')],
        ) === 1;
    }

    public function salvar(CodigoDeExclusaoDeConta $codigo): void
    {
        $this->getEntityManager()->persist($codigo);
        $this->getEntityManager()->flush();
    }
}
