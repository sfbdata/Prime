<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Enum\AbaMetas;

/**
 * A lista de metas que a tela Minhas Metas mostra para a pessoa, numa aba e com um filtro `prazo` da barra da tela. É a porta
 * da consulta de prazos e metas do chat: a implementação ({@see ListaDasMinhasMetasDaTela}) chama a MESMA consulta da tela, com
 * os mesmos recortes; o chat não tem regra de visibilidade própria.
 */
interface ListaDasMinhasMetas
{
    /**
     * @param string $filtroDePrazo um valor do filtro `prazo` da tela (`vencidas`, `proximas`)
     *
     * @return list<Tarefa>
     */
    public function daTela(User $eu, Tenant $tenant, AbaMetas $aba, string $filtroDePrazo): array;

    /**
     * Pasta, criador e responsáveis das metas já listadas, de uma vez (o mesmo carregamento dos cartões da tela), para a linha
     * não consultar meta a meta.
     *
     * @param list<int> $metaIds
     */
    public function carregarCartoes(array $metaIds, Tenant $tenant): void;
}
