<?php

declare(strict_types=1);

namespace App\Central\Repository;

use App\Central\Entity\AvisoResolvido;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AvisoResolvido> */
class AvisoResolvidoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvisoResolvido::class);
    }

    /** Dar baixa: idempotente (dois cliques ou duas abas não duplicam; a primeira hora fica). */
    public function resolver(int $tenantId, int $usuarioId, int $notificacaoId, \DateTimeImmutable $agora): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO central_aviso_resolvido (tenant_id, usuario_id, notificacao_id, resolvida_em)
             VALUES (:tenant, :usuario, :notificacao, :agora)
             ON CONFLICT (notificacao_id) DO NOTHING',
            ['tenant' => $tenantId, 'usuario' => $usuarioId, 'notificacao' => $notificacaoId, 'agora' => $agora->format('Y-m-d H:i:s')],
        );
    }

    public function reabrir(Tenant $tenant, User $usuario, int $notificacaoId): void
    {
        $this->getEntityManager()->createQuery(
            'DELETE FROM ' . AvisoResolvido::class . ' r WHERE r.tenant = :tenant AND r.usuario = :usuario AND r.notificacao = :notificacao'
        )
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('notificacao', $notificacaoId)
            ->execute();
    }

    /**
     * @param list<int> $notificacaoIds
     *
     * @return array<int, \DateTimeImmutable> id da notificação → quando foi resolvida (só as resolvidas, só minhas, deste escritório)
     */
    public function resolvidas(Tenant $tenant, User $usuario, array $notificacaoIds): array
    {
        if ($notificacaoIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.notificacao) AS notificacao', 'r.resolvidaEm')
            ->where('r.tenant = :tenant')
            ->andWhere('r.usuario = :usuario')
            ->andWhere('r.notificacao IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('ids', $notificacaoIds)
            ->getQuery()
            ->getArrayResult();

        $mapa = [];
        foreach ($linhas as $linha) {
            $mapa[(int) $linha['notificacao']] = $linha['resolvidaEm'];
        }

        return $mapa;
    }
}
