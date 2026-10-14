<?php

declare(strict_types=1);

namespace App\Central\Repository;

use App\Central\Entity\Participante;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Participante> */
class ParticipanteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Participante::class);
    }

    /**
     * A participação do usuário na conversa — é ELA que dá acesso. Conversa inexistente, de outro escritório
     * ou de que o usuário não participa dão o mesmo `null`: quem chama responde 404 sem distinguir os casos.
     */
    public function daConversa(int $conversaId, User $usuario, Tenant $tenant): ?Participante
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c')
            ->join('p.conversa', 'c')
            ->andWhere('c.id = :conversa')
            ->andWhere('p.usuario = :usuario')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('conversa', $conversaId)
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As participações do usuário no escritório, com a conversa e as duas pessoas carregadas, da conversa
     * mais recente para a mais antiga (a sem mensagem conta pela criação, e não vai para o topo por ser nula).
     *
     * @return list<Participante>
     */
    public function doUsuario(User $usuario, Tenant $tenant): array
    {
        /** @var list<Participante> $participacoes */
        $participacoes = $this->createQueryBuilder('p')
            ->addSelect('c', 'menor', 'maior')
            ->addSelect('COALESCE(c.ultimaMensagemEm, c.criadaEm) AS HIDDEN ordem')
            ->join('p.conversa', 'c')
            ->join('c.usuarioMenor', 'menor')
            ->join('c.usuarioMaior', 'maior')
            ->andWhere('p.usuario = :usuario')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->orderBy('ordem', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $participacoes;
    }

    /**
     * Avança o cursor de leitura no BANCO, numa instrução só e só para frente: duas abas marcando ao mesmo tempo
     * não fazem o cursor recuar (a guarda em PHP sobre o valor carregado perderia a corrida). Devolve o cursor
     * que ficou gravado.
     */
    public function avancarLeitura(Participante $participante, int $ateId): int
    {
        $this->getEntityManager()->createQuery(
            'UPDATE ' . Participante::class . ' p SET p.ultimaLidaId = :ate
             WHERE p.id = :id AND p.tenant = :tenant AND p.ultimaLidaId < :ate'
        )
            ->setParameter('ate', $ateId)
            ->setParameter('id', $participante->getId())
            ->setParameter('tenant', $participante->getTenant())
            ->execute();

        return (int) $this->getEntityManager()->createQuery(
            'SELECT p.ultimaLidaId FROM ' . Participante::class . ' p WHERE p.id = :id AND p.tenant = :tenant'
        )
            ->setParameter('id', $participante->getId())
            ->setParameter('tenant', $participante->getTenant())
            ->getSingleScalarResult();
    }

    public function salvar(Participante $participante): void
    {
        $this->getEntityManager()->persist($participante);
    }
}
