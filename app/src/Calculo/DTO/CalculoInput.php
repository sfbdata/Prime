<?php

declare(strict_types=1);

namespace App\Calculo\DTO;

/**
 * Entrada do cálculo, no formato que o formulário da tela guarda (desenho 14).
 *
 * `indice`: oficial | inpc | nenhum. `projecao`: media | nao. `juros`: legal | 1 | 05 | nenhum.
 * `incidencia`: valores | citacao | fixa.
 */
final readonly class CalculoInput
{
    /**
     * @param list<ValorDoCalculo> $valores
     * @param list<ValorDoCalculo> $abatimentos
     */
    public function __construct(
        public string $processo,
        public string $credor,
        public string $devedor,
        public string $ate,
        public string $indice,
        public string $projecao,
        public string $juros,
        public string $incidencia,
        public string $dataJuros,
        public string $multa,
        public string $honorarios,
        public bool $multa523,
        public bool $honorarios523,
        public array $valores,
        public array $abatimentos,
    ) {
    }

    /** @param array<string, mixed> $f */
    public static function fromArray(array $f): self
    {
        $lista = static fn (mixed $itens): array => array_values(array_map(
            static fn (mixed $i): ValorDoCalculo => ValorDoCalculo::fromArray(\is_array($i) ? $i : []),
            \is_array($itens) ? $itens : [],
        ));
        $texto = static fn (string $k, string $padrao = ''): string => trim((string) ($f[$k] ?? $padrao));
        $opcao = static fn (string $k, array $validas, string $padrao): string => \in_array($texto($k), $validas, true) ? $texto($k) : $padrao;

        return new self(
            $texto('proc'),
            $texto('credor'),
            $texto('devedor'),
            $texto('ate'),
            $opcao('idx', ['oficial', 'inpc', 'nenhum'], 'oficial'),
            $opcao('proj', ['media', 'nao'], 'media'),
            $opcao('jur', ['legal', '1', '05', 'nenhum'], 'legal'),
            $opcao('ini', ['valores', 'citacao', 'fixa'], 'valores'),
            $texto('dJur'),
            $texto('multa'),
            $texto('hon'),
            (bool) ($f['m523'] ?? false),
            (bool) ($f['h523'] ?? false),
            $lista($f['vals'] ?? []),
            $lista($f['abats'] ?? []),
        );
    }

    public function multaPct(): float
    {
        return self::percentual($this->multa);
    }

    public function honorariosPct(): float
    {
        return self::percentual($this->honorarios);
    }

    private static function percentual(string $texto): float
    {
        $limpo = str_replace(['.', ','], ['', '.'], $texto);

        return is_numeric($limpo) ? (float) $limpo : 0.0;
    }
}
