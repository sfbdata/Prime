<?php

declare(strict_types=1);

namespace App\Cliente\DTO;

use App\Cliente\Entity\Cliente;
use App\Pasta\Entity\Pasta;

/**
 * O cliente como a pessoa o vê: só as pastas que ela pode abrir, as pendências contadas pelo que ela enxerga e se pode
 * editar. Quem mostra (a janela "Detalhes do cliente", a ferramenta `ler_clientes` do conector) escolhe os campos: o
 * conector não manda valor de documento, contato nem endereço (spec mcp-remoto-oauth, 6.2, regra 4).
 */
final readonly class LeituraDoClienteOutput
{
    /**
     * @param list<Pasta>                              $pastasVisiveis do escritório, sem as excluídas, só as que a pessoa abre
     * @param list<array{texto: string, aba: string}> $pendencias
     */
    public function __construct(
        public Cliente $cliente,
        public array $pastasVisiveis,
        public array $pendencias,
        public bool $podeEditar,
    ) {
    }
}
