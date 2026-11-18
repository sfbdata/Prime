<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Pasta\Entity\AbaDaPasta;

/**
 * Uma linha da {@see TabelaDeAbaEChave}: o que uma ação de uma ferramenta do conector exige (`docs/specs/mcp-remoto-oauth.md`,
 * 6.2, regras 2 e 11, e a tabela da 6.6).
 *
 *  - {@see $modulos}: os módulos (código `modules.<x>.view`) que abrem a ação; **basta um**. Vazio: nenhum módulo
 *    exigido (o item continua pedindo o recurso, no UseCase);
 *  - {@see $abas}: as abas da pasta de onde a informação vem; **todas** precisam estar visíveis para a pessoa;
 *  - {@see $chaves}: as chaves do Master ({@see \App\Master\Service\CatalogoDeRecursos}); **todas** precisam estar
 *    ligadas, para todos, inclusive o Master;
 *  - {@see $chavesSoComPasta}: as chaves só valem quando a ação age por uma pasta (`atualizar_cliente`: "`expedientes`
 *    quando age pela pasta; nenhuma sem pasta").
 */
final class LinhaDeAbaEChave
{
    /**
     * @param list<string>     $modulos
     * @param list<AbaDaPasta> $abas
     * @param list<string>     $chaves
     */
    public function __construct(
        public readonly array $modulos,
        public readonly array $abas,
        public readonly array $chaves,
        public readonly bool $chavesSoComPasta = false,
    ) {
    }

    /**
     * As chaves que valem nesta chamada: com pasta, todas; sem pasta, nenhuma se {@see $chavesSoComPasta}.
     *
     * @return list<string>
     */
    public function chavesExigidas(bool $comPasta): array
    {
        return $this->chavesSoComPasta && !$comPasta ? [] : $this->chaves;
    }
}
