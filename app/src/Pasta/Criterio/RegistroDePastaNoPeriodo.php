<?php

declare(strict_types=1);

namespace App\Pasta\Criterio;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use Doctrine\ORM\QueryBuilder;

/**
 * O critério de "registro de pasta no período": o que a coluna Relatório do Dashboard conta e o que o
 * modal "Ver relatório" lista (regra B12: o número = a lista). É UM só para as duas pontas
 * (MetricasExtrasDoDashboardRepository::contarRegistrosPorAutor e ::listarRegistrosDoAutor): a
 * contagem troca o GROUP BY pela listagem, mas o WHERE é este. Mudar a regra aqui muda as duas.
 *
 * Os registros são as três abas que o desenho soma: Dados da pasta (`PastaMensagem`, sem os cartões
 * do sistema), Financeiro e Detalhes. Resposta é um registro e conta; edição não conta de novo (a
 * linha é a mesma). Fora: escritório diferente, pasta excluída (lápide) e pasta oculta pelo cadeado
 * para quem olha (D1). O período é sobre `criadaEm`; o corte do "Zerar" (DASH-014) é um "a partir de".
 *
 * Autor fica com quem chama (a contagem agrupa por autor; a lista filtra por um).
 */
final class RegistroDePastaNoPeriodo
{
    /** Classe do registro => aba da pasta onde ele aparece (fragmento da URL `/pasta/{id}#aba`). */
    public const ABAS = [
        PastaMensagem::class              => 'dados',
        PastaObservacaoFinanceira::class  => 'financeiro',
        PastaObservacaoDetalhes::class    => 'detalhes',
    ];

    /**
     * @param class-string           $classe       uma das chaves de ABAS
     * @param string                 $registro     alias do registro (raiz da consulta)
     * @param string                 $pasta        alias da Pasta (o chamador já fez `join('<registro>.pasta', '<pasta>')`)
     * @param \DateTimeImmutable|null $de          início (inclusive); null = sem início
     * @param \DateTimeImmutable|null $ate         fim (inclusive, já com o fim do dia); null = sem fim
     * @param \DateTimeImmutable|null $desde       corte do "Zerar"; null = sem corte
     * @param list<int>              $pastasOcultas pastas ocultas pelo cadeado para quem olha
     */
    public static function aplicar(
        QueryBuilder $qb,
        string $classe,
        string $registro,
        string $pasta,
        Tenant $tenant,
        ?\DateTimeImmutable $de,
        ?\DateTimeImmutable $ate,
        ?\DateTimeImmutable $desde = null,
        array $pastasOcultas = [],
    ): void {
        $qb->andWhere($registro . '.tenant = :rpTenant')
           ->andWhere($pasta . '.tenant = :rpTenant')
           ->andWhere($pasta . '.excluidaEm IS NULL')
           ->setParameter('rpTenant', $tenant);

        if ($classe === PastaMensagem::class) {
            $qb->andWhere($registro . '.sistema IS NULL'); // cartão do sistema não é registro de ninguém
        }
        if ($pastasOcultas !== []) {
            $qb->andWhere($pasta . '.id NOT IN (:rpOcultas)')->setParameter('rpOcultas', $pastasOcultas);
        }
        if ($de !== null) {
            $qb->andWhere($registro . '.criadaEm >= :rpDe')->setParameter('rpDe', $de);
        }
        if ($ate !== null) {
            $qb->andWhere($registro . '.criadaEm <= :rpAte')->setParameter('rpAte', $ate);
        }
        if ($desde !== null) {
            $qb->andWhere($registro . '.criadaEm >= :rpDesde')->setParameter('rpDesde', $desde);
        }
    }
}
