<?php

declare(strict_types=1);

namespace App\Conector\DTO;

/**
 * O que uma ferramenta do conector devolve (`docs/specs/mcp-remoto-oauth.md`, 6.2, regras 7 e 9):
 *  - {@see $dados}: o `structuredContent`; o despacho põe o mesmo dado, serializado, no `content`;
 *  - {@see $proximaPagina}: a página seguinte, quando a lista foi cortada (teto de 50 mil caracteres por campo): o
 *    número da página ou um cursor em texto, que o app de I.A. devolve como veio no campo `pagina`;
 *  - {@see $contagens}: números para o log `mcp` e para dizer o que ficou de fora (ex.: `em_segredo` => 2);
 *  - {@see $ids}: os ids tocados ou lidos, por tipo (ex.: `pasta` => [12]), só para o log `mcp`.
 *
 * Contagens e ids vão ao log; texto, título, nome e valor de campo nunca (regra 9).
 */
final class RespostaDaFerramenta
{
    /**
     * @param array<string, mixed>      $dados
     * @param array<string, int>        $contagens
     * @param array<string, list<int>> $ids
     */
    public function __construct(
        public readonly array $dados,
        public readonly int|string|null $proximaPagina = null,
        public readonly array $contagens = [],
        public readonly array $ids = [],
    ) {
    }
}
