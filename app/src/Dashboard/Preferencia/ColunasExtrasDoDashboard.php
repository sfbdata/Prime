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
 *   - pastas_concluidas → `Pasta.finalizadaEm` no período, por responsável ("Demandas encerradas
 *                        no período" do desenho, lida como pastas que chegaram à etapa Finalizada
 *                        do andamento no período; spec dashboard-parte-1): voltar de fase ou
 *                        reativar tira da conta, pasta excluída não conta;
 *   - eventos_agenda   → `Evento` visível à equipe em que a pessoa é criadora ou participante,
 *                        no período;
 *   - relatorio        → registros por autor em Dados da pasta, Financeiro e Detalhes, no
 *                        período (chave `comentarios` no desenho; spec dashboard-parte-1).
 *
 * O "zerar" das colunas não está aqui (DASH-014, fatia seguinte).
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
    public const PASTAS_CONCLUIDAS = 'pastas_concluidas';
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
        self::PASTAS_CONCLUIDAS => [
            'rotulo' => 'Pastas concluídas',
            'ajuda'  => 'Demandas encerradas no período',
            'tipo'   => self::TIPO_SOMA,
            'nota'   => 'Pastas arquivadas no período em que a pessoa é a responsável; desarquivar tira da conta e pasta excluída não conta. As arquivadas antes de o sistema registrar a data do arquivamento, sem registro de quando foi, ficam fora.',
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
     *   - relatório: a data de criação do registro é um fato do passado; subir é bom ('alta',
     *     o padrão do desenho para quem não é `neutra` nem SOBE_RUIM; spec dashboard-parte-1).
     *   - pastas concluídas: a data do arquivamento cai no período em que aconteceu; subir é bom
     *     ('alta', o desenho não a marca `neutra` nem `sobeRuim`). Desarquivar tira a pasta do
     *     período em que ela tinha sido arquivada, nos dois lados da comparação.
     *
     * Ficam SEM seta, com o espaço reservado:
     *   - Metas concluídas, Taxa de conclusão e Tempo médio (DASH-010b, SEGURADAS no PR-E): recortam
     *     as metas pela data de CRIAÇÃO mas leem o status/conclusão de HOJE. As metas do período
     *     anterior tiveram mais tempo para concluir, então o anterior sai maior e a seta mente
     *     (concluídas e taxa "caem", o tempo médio anterior sai maior, sem nada ter mudado no
     *     trabalho). Seta que mente é pior que ausente. O critério por data de CONCLUSÃO já existe
     *     no perfil da pessoa (PR-M), mas a célula da tabela é por criação: uma seta compararia outro
     *     recorte. Só destrava se o dono mudar o que a coluna conta (spec dashboard-parte-1).
     *   - Em revisão e Pastas urgentes: estoque, com seta própria pela foto diária. Ver ESTOQUE_COM_FOTO.
     *
     * @var array<string, string>
     */
    public const TENDENCIA = [
        self::PASTAS_CONCLUIDAS => 'alta',
        self::EVENTOS_AGENDA    => 'fila',
        self::RELATORIO         => 'alta',
    ];

    /**
     * Extras de ESTOQUE com seta pela foto diária (`dashboard_foto`, PR-F) e o sentido do desenho:
     * Em revisão é `neutra` ('fila') e Pastas urgentes é `sobeRuim` ('baixa'). Chave => coluna da foto.
     * O anterior é a foto de `data_de − 1`; o atual é o mesmo estoque lido agora (sem período), a mesma
     * régua da foto. Sem foto (ou com a coluna NULL, de antes do PR-F), sem seta: nada é inventado.
     * Metas ativas e Demandas ativas (colunas fixas, `neutra`) seguem a mesma regra.
     *
     * @var array<string, array{sentido: string, foto: string}>
     */
    public const ESTOQUE_COM_FOTO = [
        self::METAS_REVISAO   => ['sentido' => 'fila', 'foto' => 'metas_em_revisao'],
        self::PASTAS_URGENTES => ['sentido' => 'baixa', 'foto' => 'pastas_urgentes'],
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
