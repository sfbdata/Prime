<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/** Saída do MotorAvancado: o conteúdo do Modo avançado (aba Escritório) do painel. */
final readonly class LeituraAvancada
{
    /**
     * @param Alerta[]                $alertas          ordenados por nível (crítico primeiro)
     * @param array<string, int>      $contagem         nível (valor do enum) => quantidade de alertas
     * @param IndicadorDoCockpit[]    $cockpit
     * @param string[]                $qualidadeNotas
     * @param DistribuicaoDaPessoa[]  $distribuicao
     * @param PerguntaGuiada[]        $perguntas
     * @param PerguntaGuiada[]        $visaoEstrategica
     */
    public function __construct(
        public array $alertas,
        public array $contagem,
        public array $cockpit,
        public int $qualidadeNota,
        public array $qualidadeNotas,
        public array $distribuicao,
        /** Posição do traço da média na barra, em % da maior fila. */
        public int $mediaPct,
        public float $mediaValor,
        public array $perguntas,
        public array $visaoEstrategica,
        public NivelDeAlerta $pior,
    ) {}

    public function contagemDe(NivelDeAlerta $nivel): int
    {
        return $this->contagem[$nivel->value] ?? 0;
    }

    /**
     * Chips de contagem na ordem dos níveis (crítico → oportunidade), para o template.
     *
     * @return list<array{nivel: NivelDeAlerta, total: int}>
     */
    public function contagemPorNivel(): array
    {
        return array_map(
            fn (NivelDeAlerta $nivel): array => ['nivel' => $nivel, 'total' => $this->contagemDe($nivel)],
            NivelDeAlerta::cases(),
        );
    }
}
