<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaTimelineVisita;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Visitas à Timeline inteligente (PAS-043). Toda consulta é por escritório, usuário e pasta: a
 * visita é de quem olha, e a de um colega não muda nada para mim.
 *
 * @extends ServiceEntityRepository<PastaTimelineVisita>
 */
class PastaTimelineVisitaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaTimelineVisita::class);
    }

    /**
     * A abertura ANTERIOR à mais recente — o marco a partir do qual a tela conta o que é novo.
     * Nula quando o usuário nunca abriu a timeline desta pasta ou só a abriu uma vez.
     */
    public function visitaAnterior(Pasta $pasta, User $usuario, Tenant $tenant): ?\DateTimeImmutable
    {
        $visita = $this->createQueryBuilder('v')
            ->andWhere('v.tenant = :tenant')
            ->andWhere('v.usuario = :usuario')
            ->andWhere('v.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->getOneOrNullResult();

        return $visita instanceof PastaTimelineVisita ? $visita->getVistaAnteriorEm() : null;
    }

    /**
     * Registra a abertura: a visita que era a mais recente passa a ser a anterior, e `em` vira a
     * mais recente. Devolve a anterior (nula na primeira abertura).
     *
     * SQL direto com `ON CONFLICT DO UPDATE` (PostgreSQL), pelo mesmo motivo do
     * `PastaFavoritaRepository::inserirSeAusente`: duas abas abrindo ao mesmo tempo estourariam o
     * UNIQUE no caminho do ORM e fechariam o EntityManager. Aqui o banco decide a corrida, e o
     * `RETURNING` devolve o valor gravado pela própria instrução — sem reler (e sem ler de memória
     * uma entidade já carregada).
     *
     * O `WHERE` do `DO UPDATE` prende a atualização ao mesmo escritório: uma linha de (usuário,
     * pasta) nunca muda de tenant.
     */
    public function registrarAbertura(Pasta $pasta, User $usuario, Tenant $tenant, \DateTimeImmutable $em): ?\DateTimeImmutable
    {
        $anterior = $this->getEntityManager()->getConnection()->fetchOne(
            'INSERT INTO pasta_timeline_visita (tenant_id, user_id, pasta_id, vista_em, vista_anterior_em)
             VALUES (:tenant, :usuario, :pasta, :em, NULL)
             ON CONFLICT (user_id, pasta_id) DO UPDATE
                SET vista_anterior_em = pasta_timeline_visita.vista_em,
                    vista_em = EXCLUDED.vista_em
              WHERE pasta_timeline_visita.tenant_id = EXCLUDED.tenant_id
             RETURNING vista_anterior_em',
            [
                'tenant'  => $tenant->getId(),
                'usuario' => $usuario->getId(),
                'pasta'   => $pasta->getId(),
                'em'      => $em->format('Y-m-d H:i:s'),
            ],
        );

        return is_string($anterior) && $anterior !== '' ? new \DateTimeImmutable($anterior) : null;
    }
}
