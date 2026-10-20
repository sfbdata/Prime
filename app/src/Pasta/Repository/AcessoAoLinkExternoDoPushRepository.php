<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AcessoAoLinkExternoDoPush;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Acessos à página pública do link externo. As contagens da lista ("Links desta pasta") e do painel
 * "Acessos ao link do Push" (C.7) saem agregadas no banco, sem carregar uma linha por acesso.
 *
 * @extends ServiceEntityRepository<AcessoAoLinkExternoDoPush>
 */
class AcessoAoLinkExternoDoPushRepository extends ServiceEntityRepository
{
    /** O desenho mostra até 7 dias e os 5 últimos horários por link ([EXP] 4404). */
    public const DIAS = 7;
    public const ULTIMOS = 5;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcessoAoLinkExternoDoPush::class);
    }

    public function salvar(AcessoAoLinkExternoDoPush $acesso, bool $flush = false): void
    {
        $this->getEntityManager()->persist($acesso);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Por link: total, último acesso, contagem por dia (os 7 dias mais recentes com acesso, do mais
     * novo para o mais antigo) e os 5 últimos horários.
     *
     * @param int[] $linkIds
     *
     * @return array<int, array{total: int, ultimo: ?\DateTimeImmutable, porDia: array<string, int>, ultimos: list<\DateTimeImmutable>}>
     */
    public function estatisticasDosLinks(array $linkIds, Tenant $tenant): array
    {
        if ($linkIds === []) {
            return [];
        }

        $estatisticas = [];
        foreach ($linkIds as $id) {
            $estatisticas[$id] = ['total' => 0, 'ultimo' => null, 'porDia' => [], 'ultimos' => []];
        }

        foreach ($this->contagemPorDia($linkIds, $tenant) as $linha) {
            $id = (int) $linha['link_id'];
            $estatisticas[$id]['total'] += (int) $linha['total'];
            if (count($estatisticas[$id]['porDia']) < self::DIAS) {
                $estatisticas[$id]['porDia'][(string) $linha['dia']] = (int) $linha['total'];
            }
        }

        foreach ($this->ultimosHorarios($linkIds, $tenant) as $linha) {
            $quando = new \DateTimeImmutable((string) $linha['acessado_em']);
            $id = (int) $linha['link_id'];
            $estatisticas[$id]['ultimo'] ??= $quando;
            $estatisticas[$id]['ultimos'][] = $quando;
        }

        return $estatisticas;
    }

    /**
     * @param int[] $linkIds
     *
     * @return list<array<string, mixed>>
     */
    private function contagemPorDia(array $linkIds, Tenant $tenant): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT link_id, CAST(acessado_em AS DATE) AS dia, COUNT(*) AS total
               FROM push_link_externo_acesso
              WHERE tenant_id = :tenant AND link_id IN (:links)
              GROUP BY link_id, CAST(acessado_em AS DATE)
              ORDER BY link_id, dia DESC',
            ['tenant' => $tenant->getId(), 'links' => $linkIds],
            ['links' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * @param int[] $linkIds
     *
     * @return list<array<string, mixed>>
     */
    private function ultimosHorarios(array $linkIds, Tenant $tenant): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT link_id, acessado_em FROM (
                SELECT link_id, acessado_em,
                       ROW_NUMBER() OVER (PARTITION BY link_id ORDER BY acessado_em DESC, id DESC) AS ordem
                  FROM push_link_externo_acesso
                 WHERE tenant_id = :tenant AND link_id IN (:links)
             ) recentes
             WHERE ordem <= :ultimos
             ORDER BY link_id, acessado_em DESC',
            ['tenant' => $tenant->getId(), 'links' => $linkIds, 'ultimos' => self::ULTIMOS],
            ['links' => ArrayParameterType::INTEGER],
        );
    }
}
