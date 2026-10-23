<?php

declare(strict_types=1);

namespace App\Dashboard\Preferencia;

/**
 * Catálogo FECHADO do "Adicionar coluna" do menu ⋮ da tabela Desempenho (desenho
 * "01 - Dashboard 1.2.2", constante `EXTRAS`). Só entram as métricas que o sistema calcula de
 * verdade, cada uma com lastro no banco:
 *
 *   - metas_concluidas → `Tarefa.status = concluida` (Total metas − Metas ativas, no período);
 *   - taxa_conclusao   → concluídas ÷ total de metas da pessoa, no período;
 *   - metas_revisao    → `Tarefa.status = em_revisao`, no período;
 *   - tempo_medio      → dias entre `Tarefa.dataCriacao` e `Tarefa.dataConclusao`, só nas
 *                        concluídas que TÊM data de conclusão (as do legado não têm);
 *   - pastas_urgentes  → `Pasta.prioridade = urgente`, por responsável, no período;
 *   - eventos_agenda   → `Evento` visível à equipe em que a pessoa é criadora ou participante,
 *                        no período;
 *   - relatorio        → registros por autor em Dados da pasta, Financeiro e Detalhes, no
 *                        período (chave `comentarios` no desenho; spec dashboard-parte-1).
 *
 * "Pastas concluídas" (do desenho) NÃO está aqui: a pasta só tem ativo/arquivado, não existe
 * "concluída" — sem lastro (pendencias-pos-documentos, item 17). O "zerar" do Relatório também não
 * (DASH-014, fatia seguinte).
 *
 * A ordem do catálogo é a ordem da lista "Adicionar coluna" no menu (a do desenho). A ordem das
 * colunas NA TABELA é a da preferência do usuário: cada "+" acrescenta no fim.
 *
 * `tipo` decide a forma do número e do Total: `soma` (contagem; Total = soma das linhas), `pct`
 * (Total = Σ concluídas ÷ Σ metas) e `dias` (Total = Σ dias ÷ Σ metas com data).
 */
final class ColunasExtrasDoDashboard
{
    public const METAS_CONCLUIDAS = 'metas_concluidas';
    public const TAXA_CONCLUSAO   = 'taxa_conclusao';
    public const METAS_REVISAO    = 'metas_revisao';
    public const TEMPO_MEDIO      = 'tempo_medio';
    public const PASTAS_URGENTES  = 'pastas_urgentes';
    public const EVENTOS_AGENDA   = 'eventos_agenda';
    public const RELATORIO        = 'relatorio';

    public const TIPO_SOMA = 'soma';
    public const TIPO_PCT  = 'pct';
    public const TIPO_DIAS = 'dias';

    /**
     * Rótulo e ajuda são os do desenho. `nota` vai só no title do cabeçalho, quando a métrica tem
     * uma ressalva que o número sozinho não conta.
     *
     * @var array<string, array{rotulo: string, ajuda: string, tipo: string, nota: string}>
     */
    public const CATALOGO = [
        self::METAS_CONCLUIDAS => [
            'rotulo' => 'Metas concluídas',
            'ajuda'  => 'Total menos ativas, no período',
            'tipo'   => self::TIPO_SOMA,
            'nota'   => '',
        ],
        self::TAXA_CONCLUSAO => [
            'rotulo' => 'Taxa de conclusão',
            'ajuda'  => 'Concluídas ÷ total de metas',
            'tipo'   => self::TIPO_PCT,
            'nota'   => 'Sem meta no período, não há taxa (aparece como Sem dado).',
        ],
        self::METAS_REVISAO => [
            'rotulo' => 'Em revisão',
            'ajuda'  => 'Metas aguardando o gestor',
            'tipo'   => self::TIPO_SOMA,
            'nota'   => '',
        ],
        self::TEMPO_MEDIO => [
            'rotulo' => 'Tempo médio',
            'ajuda'  => 'Dias entre criar e concluir',
            'tipo'   => self::TIPO_DIAS,
            'nota'   => 'Média só das metas concluídas que têm data de conclusão; as concluídas antes de o sistema registrar essa data ficam fora.',
        ],
        self::PASTAS_URGENTES => [
            'rotulo' => 'Pastas urgentes',
            'ajuda'  => 'Pastas com prioridade Urgente',
            'tipo'   => self::TIPO_SOMA,
            'nota'   => '',
        ],
        self::EVENTOS_AGENDA => [
            'rotulo' => 'Eventos na agenda',
            'ajuda'  => 'Compromissos no período',
            'tipo'   => self::TIPO_SOMA,
            'nota'   => 'Eventos visíveis à equipe (não os "somente eu") em que a pessoa é criadora ou participante, pela data de início; cancelados ficam fora. Evento recorrente conta uma vez, pela ocorrência-base (a data de início dele), não por repetição.',
        ],
        self::RELATORIO => [
            'rotulo' => 'Relatório',
            'ajuda'  => 'Registros em Dados da pasta, Financeiro e Detalhes',
            'tipo'   => self::TIPO_SOMA,
            'nota'   => 'Registros escritos pela pessoa no período, respostas incluídas; editar não conta de novo e os avisos automáticos do sistema ficam fora.',
        ],
    ];

    /**
     * Extras com TENDÊNCIA (seta na célula, pílula no Total), com o sentido do macro
     * `_tendencia.html.twig`. Só entra a extra que se reconstrói POR DATA no período anterior
     * de mesma duração, com a mesma consulta trocando só as datas (DASH-010):
     *   - eventos: a data de início do evento é um fato do passado; neutro, sempre cinza
     *     ('fila'; desenho `neutra`).
     *
     * Ficam SEM tendência (DASH-010b), com o espaço da seta reservado como nas de estoque:
     *   - Em revisão e Pastas urgentes: são estoque (o que está em revisão/urgente agora), não
     *     se reconstroem do passado sem a foto diária;
     *   - Metas concluídas, Taxa de conclusão e Tempo médio: recortam as metas pela data de
     *     CRIAÇÃO, mas leem o status/conclusão de HOJE. As metas do período anterior tiveram
     *     mais tempo para concluir, então a comparação nasce enviesada: concluídas e taxa
     *     tenderiam a "cair" e o tempo médio anterior sairia maior, sem nada ter mudado no
     *     trabalho. É o mesmo motivo pelo qual "ativas" nunca teve seta — o estado de hoje não
     *     reconstrói o de ontem.
     *
     * PENDENTE (decisão do Samuel): o critério certo para comparar essas três — por exemplo,
     * metas concluídas DENTRO da janela por `Tarefa.dataConclusao`, com o mesmo recorte no
     * período anterior. Até lá, sem seta: o painel não mostra comparação que ele sabe torta.
     *
     * @var array<string, string>
     */
    public const TENDENCIA = [
        self::EVENTOS_AGENDA => 'fila',
    ];

    /**
     * As extras de $colunas que têm tendência, na ordem recebida.
     *
     * @param list<string> $colunas
     *
     * @return list<string>
     */
    public static function comTendencia(array $colunas): array
    {
        return array_values(array_filter(
            $colunas,
            static fn (string $coluna): bool => isset(self::TENDENCIA[$coluna]),
        ));
    }

    /** @return list<string> */
    public static function chaves(): array
    {
        return array_keys(self::CATALOGO);
    }

    public static function existe(string $chave): bool
    {
        return array_key_exists($chave, self::CATALOGO);
    }

    public static function tipo(string $chave): string
    {
        return self::CATALOGO[$chave]['tipo'] ?? self::TIPO_SOMA;
    }

    /**
     * Fica só com chaves do catálogo, sem repetição, NA ORDEM RECEBIDA (a ordem é do usuário).
     *
     * @param array<mixed> $colunas
     *
     * @return list<string>
     */
    public static function filtrar(array $colunas): array
    {
        $saida = [];
        foreach ($colunas as $coluna) {
            if (is_string($coluna) && self::existe($coluna) && !in_array($coluna, $saida, true)) {
                $saida[] = $coluna;
            }
        }

        return $saida;
    }
}
