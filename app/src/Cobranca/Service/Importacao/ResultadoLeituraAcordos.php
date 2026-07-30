<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Saída do `AcordosDetalhadosAdapter`: as abas que viraram acordos legíveis, as que foram recusadas com
 * motivo e a contagem das linhas de rodapé/cabeçalho descartadas. Irmão de `ResultadoLeitura`
 * (inadimplência) e `ResultadoLeituraCadastro`.
 */
final class ResultadoLeituraAcordos
{
    /**
     * @param list<AcordoDetalhadoImportavel> $acordos
     * @param list<LinhaRejeitada>            $rejeitadas
     */
    public function __construct(
        public readonly array $acordos,
        public readonly array $rejeitadas,
        public readonly int $linhasIgnoradas,
    ) {
    }
}
