<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * As duas setas ‹ › do cabeçalho da pasta: para onde cada uma leva e o que diz o
 * rótulo (title/aria-label). Montado a partir de `PastaRepository::vizinhasNoAcervo()`.
 */
final readonly class PastaVizinhasOutput
{
    public function __construct(
        public ?int $anteriorId,
        public string $rotuloAnterior,
        public ?int $proximaId,
        public string $rotuloProxima,
    ) {}

    /**
     * @param array{
     *     anterior: ?array{id: int, nup: ?string, nomeCliente?: ?string},
     *     proxima:  ?array{id: int, nup: ?string, nomeCliente?: ?string},
     * } $vizinhas
     */
    public static function montar(array $vizinhas): self
    {
        $anterior = $vizinhas['anterior'] ?? null;
        $proxima  = $vizinhas['proxima'] ?? null;

        return new self(
            anteriorId: $anterior['id'] ?? null,
            rotuloAnterior: $anterior === null
                ? 'Esta é a primeira pasta do acervo'
                : self::rotulo('Pasta anterior', $anterior),
            proximaId: $proxima['id'] ?? null,
            rotuloProxima: $proxima === null
                ? 'Esta é a última pasta do acervo'
                : self::rotulo('Próxima pasta', $proxima),
        );
    }

    /**
     * "Pasta anterior: MARIA DAS GRACAS (pasta 2003)" quando a vizinha tem identificador —
     * o `nomeCliente`, que o dono definiu como IDENTIFICADOR da pasta (01/09/2026). Sem ele,
     * "Pasta anterior na lista: 2003": o número continua sendo o que desambigua "anterior"
     * num acervo ordenado do maior para o menor.
     *
     * @param array{id: int, nup: ?string, nomeCliente?: ?string} $vizinha
     */
    private static function rotulo(string $prefixo, array $vizinha): string
    {
        $numero = self::identificar($vizinha);
        $nome   = trim((string) ($vizinha['nomeCliente'] ?? ''));

        if ($nome === '') {
            return $prefixo . ' na lista: ' . $numero;
        }

        return $prefixo . ': ' . $nome . ' (pasta ' . $numero . ')';
    }

    /**
     * @param array{id: int, nup: ?string} $vizinha
     */
    private static function identificar(array $vizinha): string
    {
        $nup = $vizinha['nup'];

        return ($nup !== null && trim($nup) !== '') ? $nup : '#' . $vizinha['id'];
    }
}
