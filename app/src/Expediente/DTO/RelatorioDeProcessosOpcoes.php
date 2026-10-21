<?php

declare(strict_types=1);

namespace App\Expediente\DTO;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * As opções da prévia do relatório geral de processos (desenho 1.2.3, `spPDF` L4569 e `impVals`
 * L4621-4631; spec D2): "Cabeçalho do escritório" (ligado por padrão) e "Tamanho do texto", de
 * 9 a 16 pt em passos de 0,5, começando em 10,5 pt.
 */
final class RelatorioDeProcessosOpcoes
{
    public const FONTE_MINIMA = 9.0;
    public const FONTE_MAXIMA = 16.0;
    public const FONTE_PADRAO = 10.5;

    public function __construct(
        public readonly bool $cabecalho,
        public readonly float $fonte,
    ) {
    }

    public static function daQuery(InputBag $query): self
    {
        $cabecalho = $query->get('cabecalho');

        return new self(
            cabecalho: $cabecalho === null || $cabecalho === '' || !\in_array((string) $cabecalho, ['0', 'false', 'nao', 'off'], true),
            fonte: self::fonte($query->get('fonte')),
        );
    }

    /** "10,5 pt". */
    public function fonteRotulo(): string
    {
        return str_replace('.', ',', $this->fonteCss()) . ' pt';
    }

    public function fonteCss(): string
    {
        return rtrim(rtrim(number_format($this->fonte, 1, '.', ''), '0'), '.');
    }

    private static function fonte(mixed $valor): float
    {
        $texto = str_replace(',', '.', trim((string) $valor));
        if ($texto === '' || !is_numeric($texto)) {
            return self::FONTE_PADRAO;
        }

        // Passos de 0,5, dentro de 9 a 16 (desenho: `menor`/`maior`).
        return max(self::FONTE_MINIMA, min(self::FONTE_MAXIMA, round(((float) $texto) * 2) / 2));
    }
}
