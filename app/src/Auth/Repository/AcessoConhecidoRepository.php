<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\Entity\AcessoConhecido;
use App\Entity\Auth\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AcessoConhecido>
 */
class AcessoConhecidoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcessoConhecido::class);
    }

    /** O usuário já tem algum acesso conhecido? (sem nenhum, o login é o primeiro e não avisa) */
    public function usuarioTemAcessos(User $user): bool
    {
        return $this->count(['user' => $user]) > 0;
    }

    /**
     * Grava a impressão ou, se já existe, só renova `ultimo_em`. Atômico no banco (upsert): dois logins simultâneos
     * não geram violação de unicidade, que fecharia o EntityManager no meio do login. Direto no DBAL, sem a unidade de
     * trabalho do ORM, pelo mesmo motivo.
     *
     * @return bool true se a impressão era NOVA para o usuário
     */
    public function registrar(User $user, string $impressao, \DateTimeImmutable $agora): bool
    {
        $momento = $agora->format('Y-m-d H:i:s');
        $inserida = $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO acesso_conhecido (user_id, impressao, primeiro_em, ultimo_em) VALUES (:user, :impressao, :agora, :agora) '
            . 'ON CONFLICT (user_id, impressao) DO UPDATE SET ultimo_em = EXCLUDED.ultimo_em '
            . 'RETURNING (xmax = 0)',
            ['user' => $user->getId(), 'impressao' => $impressao, 'agora' => $momento],
        );

        return filter_var($inserida, \FILTER_VALIDATE_BOOLEAN);
    }

    /** Acessos não vistos desde `$limite` (retenção): quantos seriam apagados. */
    public function contarNaoVistosDesde(\DateTimeImmutable $limite): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.ultimoEm < :limite')
            ->setParameter('limite', $limite)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return int linhas apagadas */
    public function purgarNaoVistosDesde(\DateTimeImmutable $limite): int
    {
        return (int) $this->createQueryBuilder('a')
            ->delete()
            ->where('a.ultimoEm < :limite')
            ->setParameter('limite', $limite)
            ->getQuery()
            ->execute();
    }
}
