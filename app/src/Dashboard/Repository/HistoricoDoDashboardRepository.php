<?php

declare(strict_types=1);

namespace App\Dashboard\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Séries mensais da janela "Histórico" dos cards do Dashboard (DASH-017).
 *
 * Só existe série para o que se RECONSTRÓI do passado pelas datas gravadas: hoje, Pastas criadas
 * (`p.dataAbertura`). Metas ativas, Urgentes e Meta global são retratos do momento — sem foto
 * diária não há como saber quanto valiam num mês fechado, e o painel não inventa número.
 *
 * Toda consulta recebe o Tenant e filtra por ele EXPLICITAMENTE (além do TenantFilter).
 */
// Não-final: permite substituição por mock nos testes de UseCase (como MetricasExtrasDoDashboardRepository).
class HistoricoDoDashboardRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Pastas criadas por mês, com a MESMA régua do card Pastas criadas
     * (PastaRepository::countCriadasPorCriador somado sobre as linhas): criador entre os
     * colaboradores do universo do card, sem a lápide, mês pela `dataAbertura`. Assim, somar os
     * meses de um ano dá o número do card com o período 01/01 a 31/12 daquele ano.
     *
     * A contagem por mês é feita aqui, sobre as datas do intervalo (uma consulta só): o DQL do
     * projeto não tem função de mês, e o volume — pastas de 4 anos de um escritório — é pequeno.
     *
     * A pasta oculta pelo cadeado para quem olha também não conta, como no card (spec pasta-cadeado D1).
     *
     * @param list<int> $criadorIds    universo de colaboradores (vazio = nenhuma pasta)
     * @param list<int> $pastasOcultas ids de `AcessoAPasta::idsOcultosPara` de quem olha
     *
     * @return array<string, int> 'Y-m' => total
     */
    public function contarPastasCriadasPorMes(Tenant $tenant, array $criadorIds, \DateTimeImmutable $de, \DateTimeImmutable $ate, array $pastasOcultas = []): array
    {
        if ($criadorIds === []) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()
            ->select('p.dataAbertura AS dataAbertura')
            ->from(Pasta::class, 'p')
            ->join('p.criadoPor', 'c')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('c.id IN (:criadores)')
            ->andWhere('p.dataAbertura >= :de')
            ->andWhere('p.dataAbertura <= :ate')
            ->setParameter('tenant', $tenant)
            ->setParameter('criadores', array_values($criadorIds))
            ->setParameter('de', $de)
            ->setParameter('ate', $ate);
        if ($pastasOcultas !== []) {
            $qb->andWhere('p.id NOT IN (:pastasOcultas)')->setParameter('pastasOcultas', $pastasOcultas);
        }
        $rows = $qb->getQuery()->getArrayResult();

        $porMes = [];
        foreach ($rows as $row) {
            $data = $row['dataAbertura'];
            if (!$data instanceof \DateTimeInterface) {
                continue;
            }
            $chave          = $data->format('Y-m');
            $porMes[$chave] = ($porMes[$chave] ?? 0) + 1;
        }

        return $porMes;
    }
}
