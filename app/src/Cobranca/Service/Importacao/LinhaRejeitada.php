<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * Um item da fonte que foi RECUSADO na leitura, com o motivo claro (SPEC §21: "resultado claro do que
 * foi importado, ignorado ou rejeitado"). Diferente de "ignorado" (linha que nem é dado — rodapé/total).
 */
final class LinhaRejeitada
{
    /**
     * @param array<string, string|int|float|null> $dados amostra dos campos originais (diagnóstico)
     */
    public function __construct(
        public readonly string $referencia,
        public readonly string $motivo,
        public readonly array $dados = [],
    ) {
    }
}
