<?php

declare(strict_types=1);

namespace App\Tests\AtualizacaoMonetaria;

use App\AtualizacaoMonetaria\Service\TabelaIndices;

/**
 * Monta a `TabelaIndices` a partir de `Fixtures/indices-bcb.json` — as séries reais do Banco
 * Central, congeladas.
 *
 * É o que permite ao teste de fidelidade do motor (Parte 3) ser **unitário de verdade**: sem kernel,
 * sem banco e sem rede. Não dá para ler do banco ali — o `saas_test` tem a tabela `indice_monetario`
 * vazia (o importador nunca roda em teste) e o DAMA faz rollback por teste.
 *
 * Congelar também é o que mantém os 22 casos de referência do TJDFT reprodutíveis: se o IBGE revisar
 * um índice antigo, a importação em produção muda, mas o teste continua medindo contra os mesmos
 * números que a calculadora oficial usou quando os casos foram capturados.
 *
 * **Regenerar** (só quando houver motivo, e conferindo a diferença antes de aceitar):
 *
 * ```
 * docker exec jusprime_php_dev bash -c 'cd app && php bin/console app:importar-indices-monetarios'
 * docker exec jusprime_db_dev psql -U symfony -d saas_ux -At -c "
 *   SELECT json_object_agg(serie, meses ORDER BY serie) FROM (
 *     SELECT serie, json_object_agg(to_char(competencia,'YYYY-MM-DD'), variacao_pct::text
 *            ORDER BY competencia) AS meses
 *     FROM indice_monetario GROUP BY serie) t;"
 * ```
 *
 * (o banco de dev é `saas_ux`, não `saas` — ver `app/.env.local`)
 */
final class TabelaIndicesDeFixture
{
    public const ARQUIVO = __DIR__ . '/Fixtures/indices-bcb.json';

    /** Fotografia tirada em 29/07/2026, com a carga real conferida sem buracos nas três séries. */
    public const CAPTURADO_EM = '2026-07-29';

    public static function carregar(): TabelaIndices
    {
        return new TabelaIndices(self::variacoes());
    }

    /**
     * @return array<string, array<string, string>> série => competência 'Y-m-01' => variação
     */
    public static function variacoes(): array
    {
        $conteudo = file_get_contents(self::ARQUIVO);

        if ($conteudo === false) {
            throw new \RuntimeException(sprintf(
                'Fixture de índices não encontrada em %s — o teste de fidelidade não pode rodar sem ela.',
                self::ARQUIVO,
            ));
        }

        /** @var array<string, array<string, string>> $dados */
        $dados = json_decode($conteudo, true, 512, \JSON_THROW_ON_ERROR);

        return $dados;
    }
}
