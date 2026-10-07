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
 *                        no período.
 *
 * "Pastas concluídas" (do desenho) NÃO está aqui: a pasta só tem ativo/arquivado, não existe
 * "concluída" — sem lastro (pendencias-pos-documentos, item 17). "Relatório" (comentários) também
 * não: depende de decisão do dono (zerar relatório).
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
            'nota'   => 'Sem meta no período, não há taxa (—).',
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
            'nota'   => 'Eventos visíveis à equipe (não os "somente eu") em que a pessoa é criadora ou participante, pela data de início; cancelados ficam fora.',
        ],
    ];

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
