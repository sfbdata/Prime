<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que as setas ‹ › do cabeçalho precisam mostrar, já pronto para impressão: para onde
 * cada uma leva e o que dizer quando não há para onde ir.
 *
 * A ordem é a da lista padrão do Expediente (número da pasta decrescente), então
 * "anterior" é a linha de cima e "próxima" a de baixo — ver
 * `PastaRepository::vizinhasNoAcervo()`. O número de destino entra no rótulo de propósito:
 * seta que não diz para onde leva obriga a clicar para descobrir, e "anterior" num acervo
 * ordenado do maior para o menor é ambíguo sem o número ao lado.
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
     * @param array{anterior: ?array{id: int, nup: ?string}, proxima: ?array{id: int, nup: ?string}} $vizinhas
     *        exatamente o que `PastaRepository::vizinhasNoAcervo()` devolve
     */
    public static function montar(array $vizinhas): self
    {
        $anterior = $vizinhas['anterior'] ?? null;
        $proxima  = $vizinhas['proxima'] ?? null;

        return new self(
            anteriorId: $anterior['id'] ?? null,
            rotuloAnterior: $anterior === null
                ? 'Esta é a primeira pasta do acervo'
                : 'Pasta anterior na lista: ' . self::identificar($anterior),
            proximaId: $proxima['id'] ?? null,
            rotuloProxima: $proxima === null
                ? 'Esta é a última pasta do acervo'
                : 'Próxima pasta na lista: ' . self::identificar($proxima),
        );
    }

    /**
     * Como a pasta de destino se chama na tela. Sem número (pasta importada antes da
     * numeração automática) sobra o id, que é a mesma convenção do resto do módulo
     * (`PastaController`, `PastaRepository::opcoesDoTenant`) — nunca um rótulo vazio.
     *
     * @param array{id: int, nup: ?string} $vizinha
     */
    private static function identificar(array $vizinha): string
    {
        $nup = $vizinha['nup'];

        return ($nup !== null && trim($nup) !== '') ? $nup : '#' . $vizinha['id'];
    }
}
