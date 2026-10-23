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

    /** Teto de não lidos na lista: todos entram (a contagem do lançador não pode mostrar um que a Caixa não tem). */
    public const TETO_NAO_LIDOS = 500;

    /**
     * Os da Caixa, mais recentes primeiro: TODOS os não lidos (até o teto) e, completando até `$limite`, os lidos mais
     * recentes. Com a meta, a pasta e os responsáveis (link e rechecagem sem uma consulta por aviso).
     *
     * @return list<Notificacao>
     */
    public function recentes(User $eu, Tenant $tenant, int $limite): array
    {
        $naoLidos = $this->consultar($eu, $tenant, false, self::TETO_NAO_LIDOS);
        $lidos = count($naoLidos) < $limite ? $this->consultar($eu, $tenant, true, $limite - count($naoLidos)) : [];
        $todos = array_merge($naoLidos, $lidos);
        usort($todos, static fn (Notificacao $a, Notificacao $b): int => (int) $b->getId() <=> (int) $a->getId());

        return $todos;
    }

    /** @return list<Notificacao> */
    private function consultar(User $eu, Tenant $tenant, bool $lida, int $limite): array
    {
        // Duas etapas: os ids com o limite e, depois, as entidades com as coleções (limite + fetch join de coleção não
        // se misturam no Doctrine).
        $ids = $this->em->createQuery(
            'SELECT n.id FROM ' . Notificacao::class . ' n
             WHERE n.usuario = :eu AND n.tenant = :tenant AND n.lida = :lida AND n.tipo NOT IN (:fora)
             ORDER BY n.id DESC'
        )
            ->setParameter('eu', $eu)
            ->setParameter('tenant', $tenant)
            ->setParameter('lida', $lida)
            ->setParameter('fora', TiposDeAvisoDaCentral::FORA_DA_CAIXA)
            ->setMaxResults($limite)
            ->getSingleColumnResult();
        if ($ids === []) {
            return [];
        }

        return $this->em->createQuery(
            'SELECT n, t, p, r FROM ' . Notificacao::class . ' n
             LEFT JOIN n.tarefa t LEFT JOIN t.pasta p LEFT JOIN t.responsaveis r
             WHERE n.id IN (:ids) AND n.usuario = :eu AND n.tenant = :tenant'
        )
            ->setParameter('ids', $ids)
            ->setParameter('eu', $eu)
            ->setParameter('tenant', $tenant)
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
     * meta) e pelo tipo (o nível sai do mapa único): a contagem do lançador rechecando o acesso uma vez por PASTA.
     *
     * @return list<array{pasta: ?int, responsavel: bool, tipo: string, total: int}>
     */
    public function naoLidosAgrupados(User $eu, Tenant $tenant): array
    {
        $linhas = $this->em->getConnection()->fetchAllAssociative(
            "SELECT COALESCE(t.pasta_id, CAST(substring(n.url from '^/pasta/([0-9]{1,9})') AS INTEGER)) AS pasta,
                    EXISTS (SELECT 1 FROM tarefa_responsaveis r WHERE r.tarefa_id = n.tarefa_id AND r.user_id = :eu) AS responsavel,
                    n.tipo AS tipo,
                    COUNT(*) AS total
             FROM notificacao n
             LEFT JOIN tarefa t ON t.id = n.tarefa_id AND t.tenant_id = :tenant
             WHERE n.usuario_id = :eu AND n.tenant_id = :tenant AND n.lida = false AND n.tipo NOT IN (:fora)
             GROUP BY 1, 2, 3",
            ['eu' => $eu->getId(), 'tenant' => $tenant->getId(), 'fora' => TiposDeAvisoDaCentral::FORA_DA_CAIXA],
            ['fora' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return array_map(static fn (array $l): array => [
            'pasta' => $l['pasta'] !== null ? (int) $l['pasta'] : null,
            'responsavel' => (bool) $l['responsavel'],
            'tipo' => (string) $l['tipo'],
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

    /** ⋮ "Marcar todas como lidas" (F3): os avisos da Caixa da pessoa neste escritório. */
    public function marcarTodosComoLidos(User $eu, Tenant $tenant): void
    {
        $this->em->createQuery(
            'UPDATE ' . Notificacao::class . ' n SET n.lida = true, n.lidaEm = :agora
             WHERE n.usuario = :eu AND n.tenant = :tenant AND n.lida = false AND n.tipo NOT IN (:fora)'
        )
            ->setParameter('agora', new \DateTimeImmutable())
            ->setParameter('eu', $eu)
            ->setParameter('tenant', $tenant)
            ->setParameter('fora', TiposDeAvisoDaCentral::FORA_DA_CAIXA)
            ->execute();
    }

    /** @return list<int> ⋮ "Limpar notificações lidas" (F3): os avisos JÁ LIDOS da Caixa da pessoa neste escritório */
    public function idsLidos(User $eu, Tenant $tenant): array
    {
        return array_map('intval', $this->em->createQuery(
            'SELECT n.id FROM ' . Notificacao::class . ' n
             WHERE n.usuario = :eu AND n.tenant = :tenant AND n.lida = true AND n.tipo NOT IN (:fora)'
        )
            ->setParameter('eu', $eu)
            ->setParameter('tenant', $tenant)
            ->setParameter('fora', TiposDeAvisoDaCentral::FORA_DA_CAIXA)
            ->getSingleColumnResult());
    }
}
