<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

use App\Dashboard\DTO\AnaliseDaPessoaOutput;
use App\Dashboard\DTO\ComparacaoDaPessoaOutput;
use App\Dashboard\DTO\LeituraDaPessoaOutput as Leitura;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\DTO\MatrizDaPessoaOutput;

/**
 * Matriz, leituras com etiqueta, fatores, "O que isso pode causar?" e plano de ação do perfil (dc 01,
 * `bluejus-equipe.js`), por REGRAS FIXAS sobre {@see SinaisDaPessoa}. Cada frase carrega o número real
 * que a produziu; o que o sistema não registra (complexidade, retrabalho, causa) não é inventado.
 *
 * Linguagem de apoio ao gestor: descreve volume e prazo, nunca dá nota nem manda agir sobre a pessoa
 * (LGPD art. 20). "Correlação" só quando dois números reais andam juntos; "Hipótese" só na capacidade
 * aparentemente livre, sempre pedindo conferência. As regras estão na spec (dashboard-parte-1, PR-M).
 */
final class AnaliseDaPessoa
{
    /** Sem meta e sem fila no período não há base para analisar (dc: "Sem dados no período"): devolve null. */
    public static function montar(LinhaAdvogadoDashboardOutput $linha, ?ComparacaoDaPessoaOutput $conclusoes, ?float $mediaDaEquipe): ?AnaliseDaPessoaOutput
    {
        if ($linha->totalMetas === 0 && $linha->metasAtivas === 0) {
            return null;
        }
        $s       = SinaisDaPessoa::de($linha, $conclusoes, $mediaDaEquipe);
        $alertas = self::alertas($s);
        $leituras = array_map(static fn (array $a): Leitura => $a['leitura'], $alertas);
        $evolucao = self::evolucao($s, $alertas);
        if ($evolucao !== null) {
            $leituras[] = $evolucao;
        }
        $fatores = self::fatores($s);

        return new AnaliseDaPessoaOutput(
            $leituras,
            self::matriz($s),
            $fatores,
            array_values(array_filter(array_column($alertas, 'impacto'))),
            $alertas === [] ? null : self::plano($s, $alertas, $fatores),
        );
    }

    /**
     * @return list<array{leitura: Leitura, acao: string, impacto: ?string}>
     */
    private static function alertas(SinaisDaPessoa $s): array
    {
        $a = [];
        if ($s->quedaDeConclusoes()) {
            $a[] = $s->cargaSubiu() ? self::correlacaoDeQueda($s) : self::quedaIsolada($s);
        }
        if ($s->vencidasPedemAtencao()) {
            $a[] = self::atrasos($s);
        }
        if ($s->filaAcimaDaMedia()) {
            $a[] = self::sobrecarga($s);
        }
        if ($s->filaAbaixoDaMedia() && $s->vencidas === 0) {
            $a[] = self::capacidade($s);
        }

        return $a;
    }

    /** @return array{leitura: Leitura, acao: string, impacto: ?string} */
    private static function correlacaoDeQueda(SinaisDaPessoa $s): array
    {
        return [
            'leitura' => new Leitura(Leitura::CORRELACAO, sprintf('Conclusões %s frente ao período anterior, mas a carga recebida subiu %s. A redução coincide com o aumento da demanda.', self::sinal($s->variacaoConclusoes), self::sinal($s->variacaoCarga))),
            'acao'    => 'Conferir se a distribuição de tarefas está equilibrada antes de interpretar a queda como desempenho.',
            'impacto' => null,
        ];
    }

    /** @return array{leitura: Leitura, acao: string, impacto: ?string} */
    private static function quedaIsolada(SinaisDaPessoa $s): array
    {
        $carga = $s->variacaoCarga === null ? '' : sprintf(', com carga recebida %s', self::sinal($s->variacaoCarga));

        return [
            'leitura' => new Leitura(Leitura::OBSERVADO, sprintf('Conclusões %s frente ao próprio período anterior%s.', self::sinal($s->variacaoConclusoes), $carga)),
            'acao'    => 'Acompanhar a conclusão nos próximos 7 dias e conversar com a pessoa antes de qualquer avaliação.',
            'impacto' => null,
        ];
    }

    /** @return array{leitura: Leitura, acao: string, impacto: ?string} */
    private static function atrasos(SinaisDaPessoa $s): array
    {
        $n     = (int) $s->vencidas;
        $razao = $s->razaoDeVencidas();
        $parte = $razao === null ? '' : sprintf(' (%d%% da fila ativa)', (int) round($razao * 100));

        return [
            'leitura' => new Leitura(Leitura::OBSERVADO, sprintf('%d %s%s.', $n, $n === 1 ? 'meta vencida' : 'metas vencidas', $parte)),
            'acao'    => sprintf('Tratar as %d vencidas antes de novas metas.', $n),
            'impacto' => sprintf('Com %d %s, os prazos podem exigir acompanhamento adicional.', $n, $n === 1 ? 'meta vencida' : 'metas vencidas'),
        ];
    }

    /** @return array{leitura: Leitura, acao: string, impacto: ?string} */
    private static function sobrecarga(SinaisDaPessoa $s): array
    {
        $media = (float) $s->media;

        return [
            'leitura' => new Leitura(Leitura::OBSERVADO, sprintf('%d metas ativas, %d%% da média da equipe (%s). Volume não é sinônimo de produtividade.', (int) $s->ativas, (int) round((int) $s->ativas / $media * 100), self::decimal($media))),
            'acao'    => sprintf('Avaliar a redistribuição de cerca de %d metas para quem tem capacidade disponível.', (int) ceil(((int) $s->ativas - $media) / 2)),
            'impacto' => 'Pode haver concentração de tarefas nesta pessoa.',
        ];
    }

    /** @return array{leitura: Leitura, acao: string, impacto: ?string} */
    private static function capacidade(SinaisDaPessoa $s): array
    {
        return [
            'leitura' => new Leitura(Leitura::HIPOTESE, sprintf('Há capacidade aparentemente disponível neste período (%d ativas, sem vencidas). Antes de concluir, conferir se faltaram tarefas, se há dependências, ausência ou trabalho registrado fora do sistema.', (int) $s->ativas)),
            'acao'    => 'Conferir se há trabalho a distribuir para esta pessoa.',
            'impacto' => null,
        ];
    }

    /** "Conclusões em alta": só quando nenhum alerta disparou e os prazos estão em dia. */
    private static function evolucao(SinaisDaPessoa $s, array $alertas): ?Leitura
    {
        if ($alertas !== [] || $s->variacaoConclusoes === null || $s->variacaoConclusoes < 10 || $s->vencidas === null || $s->vencidas > 1) {
            return null;
        }

        return new Leitura(Leitura::OBSERVADO, sprintf('Conclusões %s frente ao período anterior, com prazos em dia.', self::sinal($s->variacaoConclusoes)));
    }

    /**
     * Matriz do dc sem Cumprimento de prazo (o desenho mistura vencidas de hoje com metas do período) e
     * com Complexidade e Retrabalho como "Sem registro", como o próprio desenho.
     *
     * @return list<MatrizDaPessoaOutput>
     */
    private static function matriz(SinaisDaPessoa $s): array
    {
        $c = $s->variacaoCarga;
        $d = $s->variacaoConclusoes;
        $razao = $s->razaoDeVencidas();

        return [
            self::linhaDa('volume_recebido', $c === null ? ['sem_base', 'sem'] : ($c >= 20 ? ['elevada', 'atencao'] : ($c <= -20 ? ['baixa', 'info'] : ['normal', 'bom']))),
            self::linhaDa('volume_concluido', $d === null ? ['sem_base', 'sem'] : ($s->quedaDeConclusoes() ? ['atencao', 'atencao'] : ['normal', 'bom'])),
            self::linhaDa('backlog', $razao === null ? ['sem_base', 'sem'] : ($razao >= 0.4 ? ['atencao', 'atencao'] : ['normal', 'bom'])),
            self::linhaDa('complexidade', ['sem_registro', 'sem']),
            self::linhaDa('retrabalho', ['sem_registro', 'sem']),
            self::linhaDa('evolucao', $d === null ? ['sem_base', 'sem'] : ($d >= 10 ? ['melhorando', 'bom'] : ($d <= -15 ? ['piorando', 'ruim'] : ['estavel', 'neutro']))),
            self::linhaDa('capacidade', $s->media === null ? ['sem_base', 'sem'] : ($s->filaAcimaDaMedia() ? ['elevada', 'atencao'] : ($s->filaAbaixoDaMedia() ? ['baixa', 'info'] : ['normal', 'bom']))),
        ];
    }

    /** @param array{0: string, 1: string} $estadoETom */
    private static function linhaDa(string $chave, array $estadoETom): MatrizDaPessoaOutput
    {
        return new MatrizDaPessoaOutput($chave, $estadoETom[0], $estadoETom[1]);
    }

    /**
     * Fatores: só números reais que costumam andar com o problema. A "probabilidade" do desenho não
     * existe aqui (seria chute); a etiqueta é sempre Observado.
     *
     * @return list<string>
     */
    private static function fatores(SinaisDaPessoa $s): array
    {
        $f = [];
        if ($s->cargaSubiu()) {
            $f[] = sprintf('Aumento da demanda recebida (%s frente ao período anterior).', self::sinal($s->variacaoCarga));
        }
        if ($s->filaAcimaDaMedia()) {
            $f[] = sprintf('Fila ativa acima da média da equipe (%d ativas, média %s).', (int) $s->ativas, self::decimal((float) $s->media));
        }
        if ($s->emRevisao !== null && $s->emRevisao > 0) {
            $f[] = sprintf('%d %s aguardando a revisão do gestor.', $s->emRevisao, $s->emRevisao === 1 ? 'meta criada no período' : 'metas criadas no período');
        }

        return $f;
    }

    /**
     * Plano sugerido ao gestor (dc: Problema, Causa provável, Ação, Responsável, Revisão, Indicador). A
     * causa é o primeiro fator observado, ou o convite a investigar; o responsável é do gestor escolher.
     *
     * @param list<array{leitura: Leitura, acao: string, impacto: ?string}> $alertas
     * @param list<string>                                                  $fatores
     *
     * @return array<string, string>
     */
    private static function plano(SinaisDaPessoa $s, array $alertas, array $fatores): array
    {
        $razao = $s->razaoDeVencidas();

        return [
            'problema'    => $alertas[0]['leitura']->texto,
            'causa'       => $fatores[0] ?? 'Os números não indicam a causa. Vale conversar com a pessoa.',
            'acao'        => $alertas[0]['acao'],
            'responsavel' => 'A definir pelo gestor',
            'revisao'     => $razao !== null && $razao >= 0.6 ? '24 horas' : '48 horas',
            'sucesso'     => self::indicador($s),
        ];
    }

    private static function indicador(SinaisDaPessoa $s): string
    {
        return match (true) {
            $s->vencidasPedemAtencao() => 'Metas vencidas reduzidas a zero',
            $s->filaAcimaDaMedia()     => 'Fila ativa de volta à média da equipe',
            $s->quedaDeConclusoes()    => 'Conclusões de volta ao nível do período anterior',
            default                    => 'Fila ativa próxima da média da equipe',
        };
    }

    private static function sinal(?int $n): string
    {
        $n ??= 0;

        return ($n > 0 ? '+' : ($n < 0 ? '−' : '')) . abs($n) . '%';
    }

    private static function decimal(float $n): string
    {
        return number_format($n, 1, ',', '.');
    }
}
