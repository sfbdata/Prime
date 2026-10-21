<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Campanha\ListaInformada;
use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;

/**
 * O público que a tela escolheu (dc L.237-239): a chave e, no CSV e no Individual, a lista informada já lida no
 * servidor (o texto do arquivo e o do campo, com os limites da spec §8.5).
 */
final class PedidoDePublico
{
    /** @param list<DestinatarioDaCampanha> $informados */
    public function __construct(
        public readonly string $publico,
        public readonly array $informados = [],
        public readonly FiltroDoPublico $filtro = new FiltroDoPublico(),
    ) {
        if (!\array_key_exists($publico, PublicosDaCampanha::ROTULOS)) {
            throw new CampanhaRecusada('Público inválido.');
        }
    }

    /** @param array<mixed> $dados */
    public static function deArray(array $dados): self
    {
        $publico = \is_string($dados['publico'] ?? null) ? $dados['publico'] : 'leads';

        $filtro = $dados['filtro'] ?? [];
        if (!\is_array($filtro)) {
            throw new CampanhaRecusada('Filtro inválido.');
        }

        return new self($publico, match ($publico) {
            'csv' => ListaInformada::doCsv(self::texto($dados['csv'] ?? '')),
            'indiv' => ListaInformada::doIndividual(self::texto($dados['individual'] ?? '')),
            default => [],
        }, FiltroDoPublico::deArray($filtro));
    }

    private static function texto(mixed $valor): string
    {
        return \is_string($valor) ? $valor : throw new CampanhaRecusada('Pedido inválido.');
    }
}
