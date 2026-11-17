<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O andamento da pasta para quem só lê (`LerAndamentoDaPastaUseCase`; `docs/specs/mcp-remoto-oauth.md`, 6.4, `ler_pasta`
 * parte `andamento`): a via, a trilha de fases com nome e número (posição na trilha, a partir de 1), a fase atual, as
 * datas, as puladas, se a fase é estimada ou gravada, os nomes de etapa do "+" e as sugestões de próxima fase.
 *
 * Os mesmos dados que a tela monta em {@see AndamentoDaPastaOutput::de()}, sem o texto de interface (dica, ícone, "clique
 * para marcar"). Só escalar e listas; datas em `Y-m-d`.
 */
final readonly class LeituraDoAndamentoDaPastaOutput
{
    /**
     * @param array{numero: int, nome: string}                                                                                                     $atual
     * @param list<array{numero: int, nome: string, estado: string, data: ?string, pulada: bool, intervalo: ?string, registros: int, etapa: ?string}> $fases
     * @param list<array{numero: int, nome: string, motivo: string}>                                                                                $sugestoes
     */
    public function __construct(
        public string $via,
        public array $atual,
        public bool $finalizada,
        public bool $estimada,
        public ?string $baseDaEstimativa,
        public string $duracao,
        public array $fases,
        public array $sugestoes,
    ) {
    }

    /**
     * @param array{via: string, fases: list<array{0: string, 1: string}>, atual: int, em: array<int, string>, pul: list<int>, inferida: bool, base: string} $estado
     */
    public static function de(array $estado, AndamentoDaPastaOutput $tela): self
    {
        $fases = [];
        foreach ($tela->fases as $fase) {
            $k = $fase['indice'];
            $pulada = $fase['estado'] === 'pulada';
            $data = !$pulada && isset($estado['em'][$k]) ? (new \DateTimeImmutable($estado['em'][$k]))->format('Y-m-d') : null;
            $etapa = $fase['gap']['nome'] ?? '';
            $fases[] = [
                'numero' => $k + 1,
                'nome' => $fase['rotulo'],
                'estado' => $fase['estado'],
                'data' => $data,
                'pulada' => $pulada,
                'intervalo' => $fase['intervalo'] !== '' ? $fase['intervalo'] : null,
                'registros' => $fase['registros'],
                'etapa' => trim($etapa) !== '' ? $etapa : null,
            ];
        }

        $atual = $estado['atual'];
        $nomeAtual = $estado['fases'][$atual][0] ?? '';
        $ultima = \count($estado['fases']) - 1;

        return new self(
            via: $estado['via'],
            atual: ['numero' => $atual + 1, 'nome' => $nomeAtual],
            finalizada: $atual === $ultima,
            estimada: $estado['inferida'],
            baseDaEstimativa: $estado['inferida'] && $estado['base'] !== '' ? $estado['base'] : null,
            duracao: $tela->total,
            fases: $fases,
            sugestoes: array_map(
                static fn (array $s): array => ['numero' => $s['indice'] + 1, 'nome' => $s['nome'], 'motivo' => $s['motivo']],
                $tela->sugestoes,
            ),
        );
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'via' => $this->via,
            'atual' => $this->atual,
            'finalizada' => $this->finalizada,
            'estimada' => $this->estimada,
            'base_da_estimativa' => $this->baseDaEstimativa,
            'duracao' => $this->duracao,
            'fases' => $this->fases,
            'sugestoes' => $this->sugestoes,
        ];
    }
}
