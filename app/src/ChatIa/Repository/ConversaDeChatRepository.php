<?php

declare(strict_types=1);

namespace App\ChatIa\Repository;

use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\MensagemDeChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda consulta filtra por escritório E por dono: o `TenantFilter` é só a segunda barreira, e a conversa é particular
 * (a do colega, mesmo no mesmo escritório, é "não encontrada").
 *
 * @extends ServiceEntityRepository<ConversaDeChat>
 */
class ConversaDeChatRepository extends ServiceEntityRepository
{
    /** Teto da lista da barra lateral; a busca por texto é quem alcança as mais antigas. */
    public const LIMITE_DA_LISTA = 300;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversaDeChat::class);
    }

    public function doDono(int $id, Tenant $tenant, User $dono): ?ConversaDeChat
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.id = :id')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.usuario = :dono')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->setParameter('dono', $dono)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As conversas do dono: fixadas primeiro, depois as mais recentes. `$busca` casa com o título ou com o texto de
     * alguma fala (sem diferenciar caixa).
     *
     * @return list<ConversaDeChat>
     */
    public function listarDoDono(Tenant $tenant, User $dono, ?string $busca = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.usuario = :dono')
            ->setParameter('tenant', $tenant)
            ->setParameter('dono', $dono)
            ->orderBy('c.fixada', 'DESC')
            ->addOrderBy('c.atualizadaEm', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(self::LIMITE_DA_LISTA);

        $busca = trim((string) $busca);
        if ($busca !== '') {
            $qb->andWhere(sprintf(
                'LOWER(COALESCE(c.titulo, \'\')) LIKE :busca OR EXISTS (SELECT 1 FROM %s m WHERE m.conversa = c AND m.tenant = :tenant AND LOWER(m.conteudo) LIKE :busca)',
                MensagemDeChat::class,
            ))->setParameter('busca', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($busca)) . '%');
        }

        return array_values($qb->getQuery()->getResult());
    }

    public function salvar(ConversaDeChat $conversa): void
    {
        $this->getEntityManager()->persist($conversa);
    }

    public function remover(ConversaDeChat $conversa): void
    {
        $this->getEntityManager()->remove($conversa);
    }
}
