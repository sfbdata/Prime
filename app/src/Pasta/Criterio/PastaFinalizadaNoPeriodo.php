<?php

declare(strict_types=1);

namespace App\Pasta\Criterio;

use Doctrine\ORM\QueryBuilder;

/**
 * O critério de "pasta concluída no período": a pasta que chegou à etapa Finalizada do andamento
 * (`Pasta.finalizadaEm`) entre as duas datas, em qualquer situação, sem a lápide (`excluidaEm`).
 *
 * É UM só para as duas pontas da regra B12 (número clicável = tamanho da lista): a coluna "Pastas
 * concluídas" do Dashboard (MetricasExtrasDoDashboardRepository::contarFinalizadasPorResponsavel) e o
 * filtro `concluida` do Expediente (PastaRepository). Mudar a regra aqui muda as duas.
 *
 * Cadeado, escritório e responsável ficam com quem chama: este critério só fala da pasta.
 */
final class PastaFinalizadaNoPeriodo
{
    /**
     * @param string                  $alias alias da Pasta na consulta
     * @param \DateTimeImmutable|null $de    início (inclusive); null = sem início
     * @param \DateTimeImmutable|null $ate   fim (inclusive, já com o fim do dia); null = sem fim
     * @param \DateTimeImmutable|null $desde corte do "Zerar" (DASH-014); null = sem corte
     */
    public static function aplicar(QueryBuilder $qb, string $alias, ?\DateTimeImmutable $de, ?\DateTimeImmutable $ate, ?\DateTimeImmutable $desde = null): void
    {
        $qb->andWhere($alias . '.finalizadaEm IS NOT NULL')
           ->andWhere($alias . '.excluidaEm IS NULL');

        if ($de !== null) {
            $qb->andWhere($alias . '.finalizadaEm >= :pfpDe')->setParameter('pfpDe', $de);
        }
        if ($ate !== null) {
            $qb->andWhere($alias . '.finalizadaEm <= :pfpAte')->setParameter('pfpAte', $ate);
        }
        if ($desde !== null) {
            $qb->andWhere($alias . '.finalizadaEm >= :pfpDesde')->setParameter('pfpDesde', $desde);
        }
    }
}
