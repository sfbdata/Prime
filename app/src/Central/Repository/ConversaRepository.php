<?php

declare(strict_types=1);

namespace App\Central\Repository;

use App\Central\Entity\Conversa;
use App\Central\Entity\TipoDeConversa;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Conversa> */
class ConversaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversa::class);
    }

    /** A conversa individual do par, em qualquer ordem — o par é guardado ordenado. Nunca devolve a do tipo IA. */
    public function individualEntre(Tenant $tenant, User $a, User $b): ?Conversa
    {
        [$menor, $maior] = (int) $a->getId() < (int) $b->getId() ? [$a, $b] : [$b, $a];

        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.usuarioMenor = :menor')
            ->andWhere('c.usuarioMaior = :maior')
            ->andWhere('c.tipo = :individual')
            ->setParameter('individual', TipoDeConversa::Individual)
            ->setParameter('tenant', $tenant)
            ->setParameter('menor', $menor)
            ->setParameter('maior', $maior)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** A conversa do usuário com o BlueJus IA neste escritório (no máximo uma: menor = maior = ele, único). */
    public function daIaDe(Tenant $tenant, User $usuario): ?Conversa
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.tipo = :ia')
            ->andWhere('c.usuarioMenor = :usuario')
            ->andWhere('c.usuarioMaior = :usuario')
            ->setParameter('tenant', $tenant)
            ->setParameter('ia', TipoDeConversa::Ia)
            ->setParameter('usuario', $usuario)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * `FOR UPDATE` na linha da conversa, dentro de uma transação. Quem envia grava a conversa (`registrarMensagem`) na
     * mesma transação da mensagem e segura a linha até o COMMIT: esperar por ela garante que um envio em andamento
     * termine antes de quem trava ler a última mensagem (o id é reservado no INSERT e pode confirmar fora de ordem).
     */
    public function travar(Conversa $conversa, Tenant $tenant): void
    {
        $this->createQueryBuilder('c')
            ->select('c.id')
            ->andWhere('c.id = :id')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('id', $conversa->getId())
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    public function salvar(Conversa $conversa): void
    {
        $this->getEntityManager()->persist($conversa);
    }
}
