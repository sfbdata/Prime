<?php

declare(strict_types=1);

namespace App\ChatIa\Repository;

use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Enum\PapelDaMensagem;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda consulta filtra por escritório (o dono da conversa já foi conferido por quem a entregou).
 *
 * @extends ServiceEntityRepository<MensagemDeChat>
 */
class MensagemDeChatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MensagemDeChat::class);
    }

    /** @return list<MensagemDeChat> em ordem cronológica */
    public function daConversa(ConversaDeChat $conversa, Tenant $tenant): array
    {
        return array_values($this->createQueryBuilder('m')
            ->andWhere('m.conversa = :conversa')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.substituida = false')
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    public function ultima(ConversaDeChat $conversa, Tenant $tenant): ?MensagemDeChat
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.conversa = :conversa')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.substituida = false')
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function ultimaDoPapel(ConversaDeChat $conversa, Tenant $tenant, PapelDaMensagem $papel): ?MensagemDeChat
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.conversa = :conversa')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.papel = :papel')
            ->andWhere('m.substituida = false')
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
            ->setParameter('papel', $papel)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function contarDoPapel(ConversaDeChat $conversa, Tenant $tenant, PapelDaMensagem $papel): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.conversa = :conversa')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.papel = :papel')
            ->andWhere('m.substituida = false')
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
            ->setParameter('papel', $papel)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Tokens (entrada + saída; inclui as respostas substituídas, que já foram cobradas) que as conversas DESTA pessoa neste escritório gastaram desde `$desde`. */
    public function tokensDaPessoaDesde(Tenant $tenant, User $pessoa, \DateTimeImmutable $desde): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(COALESCE(m.tokensEntrada, 0) + COALESCE(m.tokensSaida, 0)), 0)')
            ->join('m.conversa', 'c')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.usuario = :pessoa')
            ->andWhere('m.criadaEm >= :desde')
            ->setParameter('tenant', $tenant)
            ->setParameter('pessoa', $pessoa)
            ->setParameter('desde', $desde)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function salvar(MensagemDeChat $mensagem): void
    {
        $this->getEntityManager()->persist($mensagem);
    }

    public function remover(MensagemDeChat $mensagem): void
    {
        $this->getEntityManager()->remove($mensagem);
    }
}
