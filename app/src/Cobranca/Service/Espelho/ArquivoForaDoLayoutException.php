<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

/**
 * O arquivo não tem o layout esperado do relatório da contabilidade (SPEC espelho §4.4).
 *
 * Recusar é o comportamento certo: o importador de hoje lê as colunas por POSIÇÃO FIXA e nunca
 * compara os nomes do cabeçalho — uma coluna deslocada passaria em silêncio e produziria número
 * errado com cara de certo, nas três carteiras. O espelho fecha essa porta.
 */
final class ArquivoForaDoLayoutException extends \RuntimeException
{
}
