<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\Pasta;

/**
 * Resumo das metas da pasta para o trilho da aba Metas (desenho 1.2.3):
 * "Andamento das metas", "Precisa de atenção" e "Responsáveis nas metas".
 *
 * Só conta o que `pasta.tarefas` já tem — nada é decidido aqui, e nada é
 * contado no template. "Atrasada" é a mesma regra da lista ao lado: meta não
 * concluída com prazo anterior a hoje (comparação entre DATAS, sem hora).
 *
 * Também entrega as LINHAS da lista (L5 da Trilha B): a ordem de criação na
 * pasta, o número local ("1.", "2." — nunca o `tarefa.id`, que é a sequência de
 * TODOS os escritórios e exporia o volume dos outros), o estado que o filtro
 * lê, os dias até o prazo (negativo = atraso; a MESMA conta do trilho, entre
 * datas, para a linha, o filtro e o "Precisa de atenção" nunca divergirem) e o
 * rótulo do prazo de meta concluída, que compara `dataConclusao` com o prazo.
 */
final readonly class PastaMetasResumoOutput
{
    /**
     * @param list<array{id: int, titulo: string, prazo: string, atraso: int, responsaveis: string}> $atencao
     * @param list<array{nome: string, iniciais: string, contagem: string}>                          $pessoas
     * @param list<array{tarefa: Tarefa, numero: int, estado: string, dias: ?int, prazoConcluida: ?string}> $linhas
     */
    private function __construct(
        public int $total,
        public int $concluidas,
        public int $pendentes,
        public int $atrasadas,
        /** Não concluídas (pendentes + em revisão + atrasadas): o filtro "Abertas". */
        public int $abertas,
        /** 0–100, inteiro. */
        public int $percentual,
        public array $atencao,
        public array $pessoas,
        public array $linhas,
    ) {
    }

    public static function montar(Pasta $pasta, ?\DateTimeImmutable $hoje = null): self
    {
        $hoje = ($hoje ?? new \DateTimeImmutable('today'))->setTime(0, 0);

        $total = $concluidas = $atrasadas = 0;
        $atencao   = [];
        $porPessoa = [];
        $linhas    = [];

        foreach (self::emOrdemDeCriacao($pasta) as $i => $tarefa) {
            ++$total;
            $concluida = $tarefa->getStatus() === Tarefa::STATUS_CONCLUIDA;
            if ($concluida) {
                ++$concluidas;
            }

            $prazo = $tarefa->getPrazo();
            $dias  = $prazo !== null ? (int) $hoje->diff($prazo->setTime(0, 0))->format('%r%a') : null;
            $atrasada = !$concluida && $dias !== null && $dias < 0;
            $linhas[] = [
                'tarefa'         => $tarefa,
                'numero'         => $i + 1,
                'estado'         => $concluida ? 'concluida' : ($atrasada ? 'atrasada' : 'aberta'),
                'dias'           => $concluida ? null : $dias,
                'prazoConcluida' => $concluida ? self::rotuloPrazoConcluida($tarefa) : null,
            ];
            if ($atrasada) {
                ++$atrasadas;
                $atencao[] = [
                    'id'           => (int) $tarefa->getId(),
                    'titulo'       => $tarefa->getTitulo(),
                    'prazo'        => $prazo->format('d/m/Y'),
                    'atraso'       => -$dias,
                    'responsaveis' => self::nomes($tarefa),
                ];
            }

            foreach ($tarefa->getResponsaveis() as $responsavel) {
                $nome = (string) $responsavel->getFullName();
                if ($nome === '') {
                    continue;
                }
                $porPessoa[$nome] = ($porPessoa[$nome] ?? 0) + 1;
            }
        }

        // A mais atrasada primeiro; quem responde por mais metas primeiro (empate: nome).
        usort($atencao, static fn (array $a, array $b) => $b['atraso'] <=> $a['atraso']);
        $pessoas = [];
        foreach ($porPessoa as $nome => $n) {
            $pessoas[] = ['nome' => $nome, 'iniciais' => self::iniciais($nome), 'contagem' => $n === 1 ? '1 meta' : $n . ' metas', 'n' => $n];
        }
        usort($pessoas, static fn (array $a, array $b) => [$b['n'], $a['nome']] <=> [$a['n'], $b['nome']]);
        $pessoas = array_map(static fn (array $p) => ['nome' => $p['nome'], 'iniciais' => $p['iniciais'], 'contagem' => $p['contagem']], $pessoas);

        return new self(
            total: $total,
            concluidas: $concluidas,
            pendentes: $total - $concluidas - $atrasadas,
            atrasadas: $atrasadas,
            abertas: $total - $concluidas,
            percentual: $total > 0 ? (int) round($concluidas * 100 / $total) : 0,
            atencao: $atencao,
            pessoas: $pessoas,
            linhas: $linhas,
        );
    }

    /**
     * Rótulo do prazo de meta CONCLUÍDA (desenho 1.2.3: "concluída no prazo dd/mm/aaaa").
     *
     * Compara DATAS (sem hora) de `dataConclusao` e `prazo`:
     * - sem prazo → null (a linha não mostra prazo, como antes);
     * - sem `dataConclusao` → "prazo dd/mm/aaaa" (o sistema não sabe afirmar quando foi);
     * - concluída até o dia do prazo → "concluída no prazo dd/mm/aaaa";
     * - depois → "concluída com N dia(s) de atraso" (o desenho é omisso; vale a convenção).
     */
    public static function rotuloPrazoConcluida(Tarefa $tarefa): ?string
    {
        $prazo = $tarefa->getPrazo();
        if ($prazo === null) {
            return null;
        }

        $conclusao = $tarefa->getDataConclusao();
        if ($conclusao === null) {
            return 'prazo ' . $prazo->format('d/m/Y');
        }

        $diaPrazo     = new \DateTimeImmutable($prazo->format('Y-m-d'));
        $diaConclusao = new \DateTimeImmutable($conclusao->format('Y-m-d'));
        $atraso       = (int) $diaPrazo->diff($diaConclusao)->format('%r%a');

        if ($atraso <= 0) {
            return 'concluída no prazo ' . $prazo->format('d/m/Y');
        }

        return 'concluída com ' . $atraso . ($atraso === 1 ? ' dia' : ' dias') . ' de atraso';
    }

    /**
     * Ordem de criação na pasta: `dataCriacao`, desempate pelo id e, sem id (meta
     * ainda não persistida), pela ordem da coleção.
     *
     * @return list<Tarefa>
     */
    private static function emOrdemDeCriacao(Pasta $pasta): array
    {
        $itens = [];
        foreach (array_values($pasta->getTarefas()->toArray()) as $i => $tarefa) {
            $itens[] = [$tarefa->getDataCriacao(), $tarefa->getId() ?? PHP_INT_MAX, $i, $tarefa];
        }
        usort($itens, static fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(static fn (array $item) => $item[3], $itens);
    }

    private static function nomes(Tarefa $tarefa): string
    {
        $nomes = [];
        foreach ($tarefa->getResponsaveis() as $responsavel) {
            $nome = (string) $responsavel->getFullName();
            if ($nome !== '') {
                $nomes[] = $nome;
            }
        }

        return implode(', ', $nomes);
    }

    /** Iniciais das DUAS primeiras palavras, como o avatar do desenho. */
    private static function iniciais(string $nome): string
    {
        $partes = preg_split('/\s+/u', trim($nome)) ?: [];
        $partes = array_values(array_filter($partes, static fn (string $p) => $p !== ''));

        return mb_strtoupper(implode('', array_map(static fn (string $p) => mb_substr($p, 0, 1), array_slice($partes, 0, 2))));
    }
}
