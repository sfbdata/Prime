<?php

declare(strict_types=1);

namespace App\Inteligencia\Contexto;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\MovimentacaoProcesso;

/**
 * De onde o {@see MontadorDeContextoDoPush} lê as movimentações. Interface para o montador ser
 * testado por unidade com dados canned; a implementação Doctrine consulta `publicacao_djen` e
 * `movimentacao_processo` com o tenant EXPLÍCITO em toda query (o worker roda sem TenantFilter).
 *
 * Vive aqui, e não nos repositórios do DJEN/Processo, porque aqueles só projetam colunas de tela
 * (sem `texto`) e esta frente não edita arquivo de outro domínio. Mover para lá é decisão do
 * orquestrador.
 */
interface FonteDeMovimentacoesDoPush
{
    /**
     * Publicações do escritório cujo número CNJ está entre os informados, mais recente primeiro.
     * Casa por NÚMERO (não pela FK de processo), como a aba Push da pasta.
     *
     * @param list<string> $numeros com ou sem máscara
     * @return list<PublicacaoDjen>
     */
    public function publicacoesDoTenant(Tenant $tenant, array $numeros, int $limite): array;

    /**
     * Movimentações (Datajud) dos processos informados, mais recente primeiro.
     *
     * @param list<int> $processoIds
     * @return list<MovimentacaoProcesso>
     */
    public function movimentacoesDoTenant(Tenant $tenant, array $processoIds, int $limite): array;

    /**
     * Só as chaves ('pub:{id}' / 'mov:{id}') do que entraria no contexto — projeção barata para a
     * tela contar "N movimentações ainda não analisadas" sem carregar texto.
     *
     * @param list<string> $numeros
     * @param list<int>    $processoIds
     * @return list<string>
     */
    public function chavesDoTenant(Tenant $tenant, array $numeros, array $processoIds, int $limitePublicacoes, int $limiteMovimentacoes): array;

    /**
     * Nomes dos colaboradores ativos do escritório (vão no cabeçalho do prompt, como no Designer).
     *
     * @return list<string>
     */
    public function nomesDaEquipe(Tenant $tenant): array;
}
