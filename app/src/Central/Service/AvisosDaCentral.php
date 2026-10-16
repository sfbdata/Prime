<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Leitura dos avisos do sino para a Caixa da Central (F1). Só os da PESSOA no escritório da sessão (tenant explícito,
 * além do TenantFilter: o sino de hoje depende só do filtro), sem os de gestão e os da própria Central.
 */
final class AvisosDaCentral
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<Notificacao> os mais recentes primeiro, com a meta e a pasta (para link e rechecagem) */
    public function recentes(User $eu, Tenant $tenant, int $limite): array
    {
        return $this->em->createQuery(
            'SELECT n, t, p FROM ' . Notificacao::class . ' n
             LEFT JOIN n.tarefa t LEFT JOIN t.pasta p
             WHERE n.usuario = :eu AND n.tenant = :tenant AND n.tipo NOT IN (:fora)
             ORDER BY n.id DESC'
        )
            ->setParameter('eu', $eu)
            ->setParameter('tenant', $tenant)
            ->setParameter('fora', TiposDeAvisoDaCentral::FORA_DA_CAIXA)
            ->setMaxResults($limite)
            ->getResult();
    }

    public function doUsuario(int $id, User $eu, Tenant $tenant): ?Notificacao
    {
        $aviso = $this->em->createQuery(
            'SELECT n FROM ' . Notificacao::class . ' n WHERE n.id = :id AND n.usuario = :eu AND n.tenant = :tenant AND n.tipo NOT IN (:fora)'
        )
            ->setParameter('id', $id)
            ->setParameter('eu', $eu)
            ->setParameter('tenant', $tenant)
            ->setParameter('fora', TiposDeAvisoDaCentral::FORA_DA_CAIXA)
            ->getOneOrNullResult();

        return $aviso instanceof Notificacao ? $aviso : null;
    }

    /**
     * Não lidos agrupados pelo que decide o acesso (a pasta, pela meta ou pela URL `/pasta/{id}`; se sou responsável pela
     * meta) e pela urgência: a contagem do lançador rechecando o acesso uma vez por PASTA, não por aviso.
     *
     * @return list<array{pasta: ?int, responsavel: bool, urgente: bool, total: int}>
     */
    public function naoLidosAgrupados(User $eu, Tenant $tenant): array
    {
        $linhas = $this->em->getConnection()->fetchAllAssociative(
            "SELECT COALESCE(t.pasta_id, CAST(substring(n.url from '^/pasta/([0-9]+)') AS INTEGER)) AS pasta,
                    EXISTS (SELECT 1 FROM tarefa_responsaveis r WHERE r.tarefa_id = n.tarefa_id AND r.user_id = :eu) AS responsavel,
                    n.tipo IN (:urgentes) AS urgente,
                    COUNT(*) AS total
             FROM notificacao n
             LEFT JOIN tarefa t ON t.id = n.tarefa_id AND t.tenant_id = :tenant
             WHERE n.usuario_id = :eu AND n.tenant_id = :tenant AND n.lida = false AND n.tipo NOT IN (:fora)
             GROUP BY 1, 2, 3",
            ['eu' => $eu->getId(), 'tenant' => $tenant->getId(), 'urgentes' => TiposDeAvisoDaCentral::URGENTES, 'fora' => TiposDeAvisoDaCentral::FORA_DA_CAIXA],
            ['urgentes' => \Doctrine\DBAL\ArrayParameterType::STRING, 'fora' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_map(static fn (array $l): array => [
            'pasta' => $l['pasta'] !== null ? (int) $l['pasta'] : null,
            'responsavel' => (bool) $l['responsavel'],
            'urgente' => (bool) $l['urgente'],
            'total' => (int) $l['total'],
        ], $linhas);
    }

    /** O maior id de aviso da pessoa (a tela recarrega a lista quando ele muda). */
    public function ultimoId(User $eu, Tenant $tenant): int
    {
        return (int) $this->em->createQuery(
            'SELECT MAX(n.id) FROM ' . Notificacao::class . ' n WHERE n.usuario = :eu AND n.tenant = :tenant AND n.tipo NOT IN (:fora)'
        )
            ->setParameter('eu', $eu)
            ->setParameter('tenant', $tenant)
            ->setParameter('fora', TiposDeAvisoDaCentral::FORA_DA_CAIXA)
            ->getSingleScalarResult();
    }
}
