<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAcompanhamentoDesligado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * "Acompanhar alterações" da pasta, guardado pela EXCEÇÃO: a linha existe só para quem desligou
 * (ver {@see PastaAcompanhamentoDesligado}). Toda consulta leva o escritório explícito: não
 * depende do TenantFilter estar ligado (o aviso pode sair fora de uma requisição).
 *
 * @extends ServiceEntityRepository<PastaAcompanhamentoDesligado>
 */
class PastaAcompanhamentoDesligadoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaAcompanhamentoDesligado::class);
    }

    public function estaDesligado(Pasta $pasta, User $usuario, Tenant $tenant): bool
    {
        $linhas = (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.usuario = :usuario')
            ->andWhere('d.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->getSingleScalarResult();

        return $linhas > 0;
    }

    /**
     * Desliga o acompanhamento de forma IDEMPOTENTE: se a linha já existe (clique duplo, duas
     * abas), não faz nada e não dá erro. SQL direto com `ON CONFLICT DO NOTHING`, pelo mesmo
     * motivo do {@see PastaFavoritaRepository::inserirSeAusente()}: no caminho do ORM a corrida
     * estouraria a UNIQUE e fecharia o EntityManager no meio da requisição.
     */
    public function desligar(Tenant $tenant, User $usuario, Pasta $pasta): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO pasta_acompanhamento_desligado (desligado_em, tenant_id, user_id, pasta_id)
             VALUES (:desligado_em, :tenant, :usuario, :pasta)
             ON CONFLICT (user_id, pasta_id) DO NOTHING',
            [
                'desligado_em' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'tenant'       => $tenant->getId(),
                'usuario'      => $usuario->getId(),
                'pasta'        => $pasta->getId(),
            ],
        );
    }

    /** Religa (apaga a exceção). Idempotente: sem linha, não apaga nada. */
    public function religar(Tenant $tenant, User $usuario, Pasta $pasta): void
    {
        $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.usuario = :usuario')
            ->andWhere('d.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->execute();
    }

    /**
     * Os candidatos a acompanhar a pasta: os VÍNCULOS ATIVOS deste escritório de usuários ATIVOS (o
     * mesmo critério de {@see \App\Repository\UserRepository::findColaboradoresAtivosPorTenant()},
     * mais o usuário ativo) que NÃO desligaram o acompanhamento desta pasta. Uma consulta só, que já
     * traz o usuário e o perfil do vínculo: quem chama confere a permissão de cada um com
     * {@see \App\Service\PermissionChecker::canAccessResourceComVinculo()} sem buscar o vínculo de novo.
     *
     * Quem chama ainda confere se cada um VÊ a pasta (permissão, cadeado, aba): isto aqui é só o
     * recorte pelo banco.
     *
     * @return list<UserTenant>
     */
    public function vinculosQueNaoDesligaram(Pasta $pasta, Tenant $tenant): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('ut', 'u', 'r')
            ->from(UserTenant::class, 'ut')
            ->join('ut.user', 'u')
            ->leftJoin('ut.tenantRole', 'r')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true')
            ->andWhere('u.isActive = true')
            ->andWhere('NOT EXISTS (SELECT d.id FROM App\Pasta\Entity\PastaAcompanhamentoDesligado d WHERE d.usuario = u AND d.pasta = :pasta AND d.tenant = :tenant)')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
