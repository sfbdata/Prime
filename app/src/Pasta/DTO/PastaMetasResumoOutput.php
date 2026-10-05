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
 */
final readonly class PastaMetasResumoOutput
{
    /**
     * @param list<array{id: int, titulo: string, prazo: string, atraso: int, responsaveis: string}> $atencao
     * @param list<array{nome: string, iniciais: string, contagem: string}>                          $pessoas
     */
    private function __construct(
        public int $total,
        public int $concluidas,
        public int $pendentes,
        public int $atrasadas,
        /** 0–100, inteiro. */
        public int $percentual,
        public array $atencao,
        public array $pessoas,
    ) {
    }

    public static function montar(Pasta $pasta, ?\DateTimeImmutable $hoje = null): self
    {
        $hoje = ($hoje ?? new \DateTimeImmutable('today'))->setTime(0, 0);

        $total = $concluidas = $atrasadas = 0;
        $atencao   = [];
        $porPessoa = [];

        foreach ($pasta->getTarefas() as $tarefa) {
            ++$total;
            $concluida = $tarefa->getStatus() === Tarefa::STATUS_CONCLUIDA;
            if ($concluida) {
                ++$concluidas;
            }

            $prazo = $tarefa->getPrazo();
            $dias  = $prazo !== null ? (int) $hoje->diff($prazo->setTime(0, 0))->format('%r%a') : null;
            if (!$concluida && $dias !== null && $dias < 0) {
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
            percentual: $total > 0 ? (int) round($concluidas * 100 / $total) : 0,
            atencao: $atencao,
            pessoas: $pessoas,
        );
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
